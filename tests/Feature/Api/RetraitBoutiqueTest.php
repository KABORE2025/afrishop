<?php

namespace Tests\Feature\Api;

use App\Models\SousCommande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

/**
 * Retrait en boutique : le client choisit `mode_livraison = retrait_boutique`
 * à la commande, le vendeur prépare (code envoyé par SMS), puis remet le
 * colis en saisissant ce code — sans transporteur ni livreur.
 */
class RetraitBoutiqueTest extends TestCase
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
            'articles' => [['variante_id' => $variante->id, 'quantite' => 1]],
            'nom' => 'Client Test', 'telephone' => '22670001122',
            'ville_id' => $ville->id, 'quartier' => 'Zone 1',
            'mode_livraison' => 'retrait_boutique',
            'mode_paiement' => 'mobile_money',
        ])->assertCreated();

        $sousCommande = SousCommande::where('boutique_id', $boutique->id)->firstOrFail();

        return compact('pays', 'boutique', 'vendeur', 'sousCommande');
    }

    public function test_preparation_puis_confirmation_du_retrait_livrent_la_sous_commande(): void
    {
        ['vendeur' => $vendeur, 'sousCommande' => $sc] = $this->creerContexte();

        $reponsePreparer = $this->actingAs($vendeur, 'sanctum')
            ->postJson("/api/vendeur/commandes/{$sc->id}/preparer-retrait");
        $reponsePreparer->assertOk();
        $this->assertSame('prete', $sc->fresh()->statut);

        $code = $sc->fresh()->code_retrait;
        $this->assertNotNull($code);

        $reponseConfirmer = $this->actingAs($vendeur, 'sanctum')
            ->postJson("/api/vendeur/commandes/{$sc->id}/confirmer-retrait", ['code_retrait' => $code]);
        $reponseConfirmer->assertOk();

        $fraiche = $sc->fresh();
        $this->assertSame('livree', $fraiche->statut);
        $this->assertNotNull($fraiche->confirme_par_client_le);
        $this->assertSame('livree', $fraiche->commande->fresh()->statut);

        // Le client vient de confirmer en personne : les fonds sont
        // libérés tout de suite, sans attendre le balayage à J+3, qui de
        // toute façon ignore les sous-commandes déjà confirmées.
        $this->assertSame('reverse', $fraiche->etat_fonds->value);
    }

    public function test_confirmation_avec_un_mauvais_code_est_rejetee(): void
    {
        ['vendeur' => $vendeur, 'sousCommande' => $sc] = $this->creerContexte();

        $this->actingAs($vendeur, 'sanctum')->postJson("/api/vendeur/commandes/{$sc->id}/preparer-retrait");

        $reponse = $this->actingAs($vendeur, 'sanctum')
            ->postJson("/api/vendeur/commandes/{$sc->id}/confirmer-retrait", ['code_retrait' => '000000']);

        $reponse->assertStatus(422);
        $this->assertSame('prete', $sc->fresh()->statut);
        $this->assertSame(1, $sc->fresh()->retrait_tentatives);
    }

    public function test_preparation_refusee_si_la_commande_nest_pas_en_retrait_boutique(): void
    {
        $pays = $this->creerPays();
        $ville = $this->creerVille($pays);
        $this->creerZoneLivraison($pays, $ville);
        $categorie = $this->creerCategorie();
        ['boutique' => $boutique, 'utilisateur' => $vendeur] = $this->creerBoutiqueAvecVendeur($pays, ['ville_id' => $ville->id]);
        ['variante' => $variante] = $this->creerProduitAvecVariante($boutique, $categorie, [], ['stock' => 10]);

        $this->postJson('/api/commandes', [
            'pays_id' => $pays->id,
            'articles' => [['variante_id' => $variante->id, 'quantite' => 1]],
            'nom' => 'Client Test', 'telephone' => '22670001122',
            'ville_id' => $ville->id, 'quartier' => 'Zone 1',
            'mode_paiement' => 'mobile_money',
        ])->assertCreated();

        $sc = SousCommande::where('boutique_id', $boutique->id)->firstOrFail();

        $this->actingAs($vendeur, 'sanctum')
            ->postJson("/api/vendeur/commandes/{$sc->id}/preparer-retrait")
            ->assertStatus(422);
    }
}
