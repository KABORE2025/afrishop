<?php

namespace Tests\Feature;

use App\Services\PanierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

/**
 * PanierService::creerCommande() est le cœur financier du système :
 * éclatement par boutique, commission, retenue à la source, répartition
 * des frais de livraison. Ce test reproduit l'exemple chiffré du dossier
 * de conception (vente de 24 000 F, vendeur non immatriculé).
 */
class PanierServiceCommandeTest extends TestCase
{
    use RefreshDatabase, CreeDonneesTrait;

    public function test_eclatement_sur_deux_boutiques_avec_commission_et_retenue_correctes(): void
    {
        $pays = $this->creerPays();
        $ville = $this->creerVille($pays);
        $this->creerZoneLivraison($pays, $ville, ['frais_base_cfa' => 1500, 'frais_boutique_sup_cfa' => 900]);
        $categorie = $this->creerCategorie();

        // Boutique A : non immatriculée — retenue à 25 % (le taux le
        // plus lourd de la zone, exemple exact du dossier de conception).
        ['boutique' => $boutiqueA] = $this->creerBoutiqueAvecVendeur($pays, [
            'taux_commission' => 12, 'numero_fiscal' => null, 'regime_fiscal' => 'non_immatricule',
        ]);
        ['produit' => $produitA] = $this->creerProduitAvecVariante($boutiqueA, $categorie, [
            'nom' => 'Beurre de karité A', 'prix_ttc_cfa' => 24_000,
        ], ['stock' => 10, 'prix_ttc_cfa' => null]);

        // Boutique B : immatriculée — retenue ramenée à 5 %.
        ['boutique' => $boutiqueB] = $this->creerBoutiqueAvecVendeur($pays, [
            'taux_commission' => 10, 'numero_fiscal' => 'IFU-000123', 'regime_fiscal' => 'reel',
        ]);
        ['produit' => $produitB] = $this->creerProduitAvecVariante($boutiqueB, $categorie, [
            'nom' => 'Pagne tissé B', 'prix_ttc_cfa' => 24_000,
        ], ['stock' => 10, 'prix_ttc_cfa' => null]);

        $panier = app(PanierService::class);

        $commande = $panier->creerCommande(
            articles: [
                ['variante_id' => $produitA->variantes()->first()->id, 'quantite' => 1],
                ['variante_id' => $produitB->variantes()->first()->id, 'quantite' => 1],
            ],
            client: [
                'nom' => 'Client Test', 'telephone' => '22670000000',
                'ville_id' => $ville->id, 'quartier' => 'Zone 1',
                'mode_livraison' => 'domicile', 'mode_paiement' => 'especes_livraison',
            ],
            pays: $pays,
        );

        $sousCommandes = $commande->sousCommandes()->orderBy('id')->get();
        $this->assertCount(2, $sousCommandes);

        // Frais de livraison : 1500 de base + 900 pour la 2e boutique =
        // 2400, répartis également puisque divisibles par 2.
        $this->assertSame(2_400, $commande->total_frais_livraison_cfa);
        $this->assertSame(50_400, $commande->total_a_payer_cfa); // 24 000 × 2 + 2 400

        $scA = $sousCommandes->firstWhere('boutique_id', $boutiqueA->id);
        $this->assertSame(24_000, $scA->montant_articles_ttc_cfa);
        $this->assertSame(2_880, $scA->commission_cfa);       // 24 000 × 12 %
        $this->assertSame(6_000, $scA->retenue_source_cfa);   // 24 000 × 25 %
        $this->assertSame(15_120, $scA->montant_net_cfa);     // 24 000 − 2 880 − 6 000
        $this->assertSame(1_200, $scA->frais_livraison_cfa);
        $this->assertSame('attente_encaissement', $scA->etat_fonds->value);

        $scB = $sousCommandes->firstWhere('boutique_id', $boutiqueB->id);
        $this->assertSame(2_400, $scB->commission_cfa);       // 24 000 × 10 %
        $this->assertSame(1_200, $scB->retenue_source_cfa);   // 24 000 × 5 %
        $this->assertSame(20_400, $scB->montant_net_cfa);

        // Le stock a été décrémenté.
        $this->assertSame(9, $produitA->variantes()->first()->fresh()->stock);
    }

    public function test_stock_insuffisant_leve_une_exception_et_ne_cree_rien(): void
    {
        $pays = $this->creerPays();
        $ville = $this->creerVille($pays);
        $this->creerZoneLivraison($pays, $ville);
        $categorie = $this->creerCategorie();
        ['boutique' => $boutique] = $this->creerBoutiqueAvecVendeur($pays);
        ['variante' => $variante] = $this->creerProduitAvecVariante($boutique, $categorie, [], ['stock' => 1]);

        try {
            app(PanierService::class)->creerCommande(
                articles: [['variante_id' => $variante->id, 'quantite' => 5]],
                client: ['nom' => 'Client', 'telephone' => '22670000000', 'quartier' => 'Zone 1', 'mode_paiement' => 'especes_livraison'],
                pays: $pays,
            );
            $this->fail('Une exception RuntimeException était attendue pour stock insuffisant.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Stock insuffisant', $e->getMessage());
        }

        $this->assertSame(0, \App\Models\Commande::count());
        $this->assertSame(1, $variante->fresh()->stock);
    }
}
