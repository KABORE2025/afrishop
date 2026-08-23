<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

class VendeurProduitTest extends TestCase
{
    use RefreshDatabase, CreeDonneesTrait;

    public function test_creation_dun_produit_sans_variante_genere_une_variante_standard(): void
    {
        $pays = $this->creerPays();
        $categorie = $this->creerCategorie();
        ['boutique' => $boutique, 'utilisateur' => $vendeur] = $this->creerBoutiqueAvecVendeur($pays);

        $reponse = $this->actingAs($vendeur, 'sanctum')->postJson('/api/vendeur/produits', [
            'nom'          => 'Savon noir artisanal',
            'categorie_id' => $categorie->id,
            'prix_ttc_cfa' => 1_500,
        ]);

        $reponse->assertCreated();
        $reponse->assertJsonPath('nom', 'Savon noir artisanal');
        $reponse->assertJsonPath('boutique_id', $boutique->id);
        $reponse->assertJsonCount(1, 'variantes');
        $reponse->assertJsonPath('variantes.0.libelle', 'Standard');
        $reponse->assertJsonPath('variantes.0.defaut', true);
    }

    public function test_creation_dun_produit_avec_variantes_explicites(): void
    {
        $pays = $this->creerPays();
        $categorie = $this->creerCategorie();
        ['utilisateur' => $vendeur] = $this->creerBoutiqueAvecVendeur($pays);

        $reponse = $this->actingAs($vendeur, 'sanctum')->postJson('/api/vendeur/produits', [
            'nom'          => 'Pagne tissé',
            'categorie_id' => $categorie->id,
            'prix_ttc_cfa' => 8_000,
            'variantes'    => [
                ['libelle' => 'Bleu — 2 m', 'stock' => 5],
                ['libelle' => 'Rouge — 2 m', 'stock' => 3, 'prix_ttc_cfa' => 8_500],
            ],
        ]);

        $reponse->assertCreated();
        $reponse->assertJsonCount(2, 'variantes');
        $this->assertDatabaseHas('variantes_produit', ['libelle' => 'Rouge — 2 m', 'prix_ttc_cfa' => 8_500, 'stock' => 3]);
    }

    public function test_un_vendeur_ne_voit_que_les_produits_de_sa_boutique(): void
    {
        $pays = $this->creerPays();
        $categorie = $this->creerCategorie();
        ['boutique' => $boutiqueA, 'utilisateur' => $vendeurA] = $this->creerBoutiqueAvecVendeur($pays);
        ['boutique' => $boutiqueB] = $this->creerBoutiqueAvecVendeur($pays);

        $this->creerProduitAvecVariante($boutiqueA, $categorie, ['nom' => 'Produit A']);
        $this->creerProduitAvecVariante($boutiqueB, $categorie, ['nom' => 'Produit B']);

        $reponse = $this->actingAs($vendeurA, 'sanctum')->getJson('/api/vendeur/produits');

        $reponse->assertOk();
        $noms = collect($reponse->json('data'))->pluck('nom')->all();
        $this->assertContains('Produit A', $noms);
        $this->assertNotContains('Produit B', $noms);
    }

    public function test_creerproduit_est_refuse_sans_authentification_vendeur(): void
    {
        $categorie = $this->creerCategorie();

        $this->postJson('/api/vendeur/produits', [
            'nom' => 'X', 'categorie_id' => $categorie->id, 'prix_ttc_cfa' => 1000,
        ])->assertStatus(401);
    }
}
