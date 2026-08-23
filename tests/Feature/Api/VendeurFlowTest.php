<?php

namespace Tests\Feature\Api;

use App\Models\SousCommande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

/**
 * Bout en bout : commande en espèces → expédition → livraison. C'est le
 * chemin qui bascule l'état des fonds de « attente_encaissement » à
 * « sequestre » et qui écrit au grand livre (EspecesService).
 */
class VendeurFlowTest extends TestCase
{
    use RefreshDatabase, CreeDonneesTrait;

    private function creerContexte(): array
    {
        $pays = $this->creerPays();
        $ville = $this->creerVille($pays);
        $this->creerZoneLivraison($pays, $ville);
        $categorie = $this->creerCategorie();
        ['boutique' => $boutique, 'utilisateur' => $vendeur] = $this->creerBoutiqueAvecVendeur($pays);
        ['variante' => $variante] = $this->creerProduitAvecVariante($boutique, $categorie, [], ['stock' => 10, 'prix_ttc_cfa' => 5_000]);

        $reponse = $this->postJson('/api/commandes', [
            'pays_id'       => $pays->id,
            'articles'      => [['variante_id' => $variante->id, 'quantite' => 2]],
            'nom'           => 'Client Test', 'telephone' => '22670001122',
            'ville_id'      => $ville->id, 'quartier' => 'Zone 1',
            'mode_paiement' => 'especes_livraison',
        ]);

        $sousCommande = SousCommande::where('boutique_id', $boutique->id)->firstOrFail();

        return compact('pays', 'boutique', 'vendeur', 'sousCommande');
    }

    public function test_expedition_puis_livraison_en_especes_libere_les_ecritures_comptables(): void
    {
        ['boutique' => $boutique, 'vendeur' => $vendeur, 'sousCommande' => $sc] = $this->creerContexte();

        $reponseExpedier = $this->actingAs($vendeur, 'sanctum')
            ->postJson("/api/vendeur/commandes/{$sc->id}/expedier", []);
        $reponseExpedier->assertOk();
        $this->assertSame('expediee', $sc->fresh()->statut);

        $codeLivraison = $sc->fresh()->expedition->code_livraison;
        $this->assertNotNull($codeLivraison);
        $this->assertDatabaseHas('encaissements_especes', ['statut' => 'a_encaisser']);

        // Montant dû : 2 × 5000 + frais de livraison (1500, une seule boutique).
        $montantDu = $sc->fresh()->montant_articles_ttc_cfa + $sc->fresh()->frais_livraison_cfa;

        $reponseLivrer = $this->actingAs($vendeur, 'sanctum')
            ->postJson("/api/vendeur/commandes/{$sc->id}/livrer", [
                'code_livraison'    => $codeLivraison,
                'montant_percu_cfa' => $montantDu,
            ]);
        $reponseLivrer->assertOk();

        $frais = $sc->fresh();
        $this->assertSame('livree', $frais->statut);
        $this->assertSame('sequestre', $frais->etat_fonds->value);

        $this->assertDatabaseHas('mouvements_compte', [
            'boutique_id' => $boutique->id, 'type' => 'vente', 'sens' => 'credit',
        ]);
        $this->assertDatabaseHas('encaissements_especes', ['statut' => 'encaisse']);
    }

    public function test_livraison_avec_un_mauvais_code_est_rejetee(): void
    {
        ['vendeur' => $vendeur, 'sousCommande' => $sc] = $this->creerContexte();

        $this->actingAs($vendeur, 'sanctum')->postJson("/api/vendeur/commandes/{$sc->id}/expedier", []);

        $reponse = $this->actingAs($vendeur, 'sanctum')
            ->postJson("/api/vendeur/commandes/{$sc->id}/livrer", [
                'code_livraison' => '000000', 'montant_percu_cfa' => 1000,
            ]);

        $reponse->assertStatus(422);
        $this->assertSame('expediee', $sc->fresh()->statut);
    }

    public function test_un_vendeur_ne_peut_pas_agir_sur_la_sous_commande_dune_autre_boutique(): void
    {
        ['pays' => $pays, 'sousCommande' => $sc] = $this->creerContexte();
        ['utilisateur' => $autreVendeur] = $this->creerBoutiqueAvecVendeur($pays);

        $reponse = $this->actingAs($autreVendeur, 'sanctum')
            ->postJson("/api/vendeur/commandes/{$sc->id}/expedier", []);

        $reponse->assertStatus(403);
    }
}
