<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

class CommandeCreationTest extends TestCase
{
    use RefreshDatabase, CreeDonneesTrait;

    public function test_commande_en_especes_a_la_livraison_est_creee_sans_encaissement(): void
    {
        $pays = $this->creerPays();
        $ville = $this->creerVille($pays);
        $this->creerZoneLivraison($pays, $ville);
        $categorie = $this->creerCategorie();
        ['boutique' => $boutique] = $this->creerBoutiqueAvecVendeur($pays);
        ['variante' => $variante] = $this->creerProduitAvecVariante($boutique, $categorie, [], ['stock' => 10]);

        $reponse = $this->postJson('/api/commandes', [
            'pays_id'        => $pays->id,
            'articles'       => [['variante_id' => $variante->id, 'quantite' => 2]],
            'nom'            => 'Aminata Client',
            'telephone'      => '22670001122',
            'ville_id'       => $ville->id,
            'quartier'       => 'Zone 1',
            'mode_paiement'  => 'especes_livraison',
        ]);

        $reponse->assertCreated();
        $reponse->assertJsonPath('statut_paiement', 'attente');
        $reponse->assertJsonPath('sous_commandes.0.etat_fonds', 'attente_encaissement');

        $this->assertSame(8, $variante->fresh()->stock);
        $this->assertDatabaseCount('commandes', 1);
        $this->assertDatabaseCount('sous_commandes', 1);
    }

    public function test_commande_avec_stock_insuffisant_renvoie_une_erreur_claire(): void
    {
        $pays = $this->creerPays();
        $ville = $this->creerVille($pays);
        $this->creerZoneLivraison($pays, $ville);
        $categorie = $this->creerCategorie();
        ['boutique' => $boutique] = $this->creerBoutiqueAvecVendeur($pays);
        ['variante' => $variante] = $this->creerProduitAvecVariante($boutique, $categorie, [], ['stock' => 1]);

        $reponse = $this->postJson('/api/commandes', [
            'pays_id'        => $pays->id,
            'articles'       => [['variante_id' => $variante->id, 'quantite' => 5]],
            'nom'            => 'Aminata Client',
            'telephone'      => '22670001122',
            'quartier'       => 'Zone 1',
            'mode_paiement'  => 'especes_livraison',
        ]);

        $reponse->assertStatus(422);
        $reponse->assertJsonFragment(['message' => "Stock insuffisant pour « Beurre de karité brut 500 g » : il en reste 1."]);
        $this->assertDatabaseCount('commandes', 0);
    }

    public function test_champs_obligatoires_manquants_sont_rejetes(): void
    {
        $this->postJson('/api/commandes', [])->assertStatus(422)
            ->assertJsonValidationErrors(['pays_id', 'articles', 'nom', 'telephone', 'quartier', 'mode_paiement']);
    }

    public function test_commande_en_mobile_money_est_encaissee_par_la_passerelle_de_secours(): void
    {
        $pays = $this->creerPays();
        $ville = $this->creerVille($pays);
        $this->creerZoneLivraison($pays, $ville);
        $categorie = $this->creerCategorie();
        ['boutique' => $boutique] = $this->creerBoutiqueAvecVendeur($pays);
        ['variante' => $variante] = $this->creerProduitAvecVariante($boutique, $categorie, [], ['stock' => 10]);

        $reponse = $this->postJson('/api/commandes', [
            'pays_id'        => $pays->id,
            'articles'       => [['variante_id' => $variante->id, 'quantite' => 1]],
            'nom'            => 'Aminata Client',
            'telephone'      => '22670001122',
            'ville_id'       => $ville->id,
            'quartier'       => 'Zone 1',
            'mode_paiement'  => 'mobile_money',
        ]);

        $reponse->assertCreated();
        $reponse->assertJsonPath('statut_paiement', 'encaisse');

        $this->assertDatabaseHas('transactions_paiement', ['statut' => 'reussie']);
        // La passerelle « fake » simule un encaissement immédiat : les
        // écritures au grand livre doivent déjà exister.
        $this->assertDatabaseHas('mouvements_compte', ['boutique_id' => $boutique->id, 'type' => 'vente', 'sens' => 'credit']);
    }
}
