<?php

namespace Tests\Feature\Api;

use App\Models\AgentRemiseBoutique;
use App\Models\SousCommande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

/**
 * Bout en bout : commande payée en ligne → expédition → livraison par un
 * livreur rattaché à la boutique (agents-remise, même ville). Le paiement
 * étant encaissé dès la création (mobile_money via la passerelle « fake »),
 * l'écriture au grand livre existe déjà avant même l'expédition — seul le
 * statut logistique de la sous-commande évolue ici.
 */
class VendeurFlowTest extends TestCase
{
    use CreeDonneesTrait, RefreshDatabase;

    private function creerContexte(): array
    {
        $pays = $this->creerPays();
        $ville = $this->creerVille($pays);
        $this->creerZoneLivraison($pays, $ville);
        $categorie = $this->creerCategorie();
        ['boutique' => $boutique, 'utilisateur' => $vendeur] = $this->creerBoutiqueAvecVendeur($pays, ['ville_id' => $ville->id]);
        ['variante' => $variante] = $this->creerProduitAvecVariante($boutique, $categorie, [], ['stock' => 10, 'prix_ttc_cfa' => 5_000]);

        $this->postJson('/api/commandes', [
            'pays_id' => $pays->id,
            'articles' => [['variante_id' => $variante->id, 'quantite' => 2]],
            'nom' => 'Client Test', 'telephone' => '22670001122',
            'ville_id' => $ville->id, 'quartier' => 'Zone 1',
            'mode_paiement' => 'mobile_money',
        ])->assertCreated();

        $sousCommande = SousCommande::where('boutique_id', $boutique->id)->firstOrFail();

        $livreur = $this->creerUtilisateur($pays, ['role' => 'livreur', 'ville_id' => $ville->id]);
        AgentRemiseBoutique::create([
            'boutique_id' => $boutique->id, 'livreur_id' => $livreur->id,
            'statut' => 'actif', 'autorise_livraison' => true,
            'invite_par_utilisateur_id' => $vendeur->id, 'accepte_le' => now(),
        ]);

        return compact('pays', 'boutique', 'vendeur', 'livreur', 'sousCommande');
    }

    public function test_expedition_puis_livraison_font_passer_la_sous_commande_a_livree(): void
    {
        ['vendeur' => $vendeur, 'livreur' => $livreur, 'sousCommande' => $sc] = $this->creerContexte();

        $reponseExpedier = $this->actingAs($vendeur, 'sanctum')
            ->postJson("/api/vendeur/commandes/{$sc->id}/expedier", ['livreur_id' => $livreur->id]);
        $reponseExpedier->assertOk();
        $this->assertSame('expediee', $sc->fresh()->statut);

        $codeLivraison = $sc->fresh()->expedition->code_livraison;
        $this->assertNotNull($codeLivraison);

        $reponseLivrer = $this->actingAs($livreur, 'sanctum')
            ->postJson("/api/livreur/commandes/{$sc->id}/livrer", ['code_livraison' => $codeLivraison]);
        $reponseLivrer->assertOk();

        $fraiche = $sc->fresh();
        $this->assertSame('livree', $fraiche->statut);
        $this->assertSame('livree', $fraiche->commande->fresh()->statut);

        // Le code prouve la remise, pas la conformité : les fonds restent
        // en séquestre pendant la fenêtre de protection du client (72 h).
        $this->assertNull($fraiche->confirme_par_client_le);
        $this->assertSame('sequestre', $fraiche->etat_fonds->value);

        // Payée en mobile_money : le grand livre a été écrit dès la
        // création de la commande, pas à la livraison.
        $this->assertDatabaseHas('mouvements_compte', [
            'boutique_id' => $fraiche->boutique_id, 'type' => 'vente', 'sens' => 'credit',
        ]);
    }

    public function test_livraison_avec_un_mauvais_code_est_rejetee(): void
    {
        ['vendeur' => $vendeur, 'livreur' => $livreur, 'sousCommande' => $sc] = $this->creerContexte();

        $this->actingAs($vendeur, 'sanctum')->postJson("/api/vendeur/commandes/{$sc->id}/expedier", ['livreur_id' => $livreur->id]);

        $reponse = $this->actingAs($livreur, 'sanctum')
            ->postJson("/api/livreur/commandes/{$sc->id}/livrer", ['code_livraison' => '000000']);

        $reponse->assertStatus(422);
        $this->assertSame('expediee', $sc->fresh()->statut);
    }

    public function test_un_vendeur_ne_peut_pas_agir_sur_la_sous_commande_dune_autre_boutique(): void
    {
        ['pays' => $pays, 'sousCommande' => $sc] = $this->creerContexte();
        ['utilisateur' => $autreVendeur] = $this->creerBoutiqueAvecVendeur($pays);
        $livreur = $this->creerUtilisateur($pays, ['role' => 'livreur']);

        $reponse = $this->actingAs($autreVendeur, 'sanctum')
            ->postJson("/api/vendeur/commandes/{$sc->id}/expedier", ['livreur_id' => $livreur->id]);

        $reponse->assertStatus(403);
    }
}
