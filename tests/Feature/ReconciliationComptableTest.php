<?php

namespace Tests\Feature;

use App\Models\SousCommande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

/**
 * Reproduit le test canonique du dossier de conception (§ 9.2) :
 *
 *   encaissé = dû aux boutiques + commissions + retenues + frais de livraison
 *
 * C'est l'identité comptable qui doit toujours être vraie sur une
 * commande en espèces entièrement encaissée. Une seule erreur d'arrondi
 * quelque part dans PanierService/GrandLivreService la casserait.
 */
class ReconciliationComptableTest extends TestCase
{
    use RefreshDatabase, CreeDonneesTrait;

    public function test_une_commande_encaissee_sequilibre_exactement(): void
    {
        $pays = $this->creerPays();
        $ville = $this->creerVille($pays);
        $this->creerZoneLivraison($pays, $ville, ['frais_base_cfa' => 1500, 'frais_boutique_sup_cfa' => 900]);
        $categorie = $this->creerCategorie();

        ['boutique' => $boutiqueA, 'utilisateur' => $vendeurA] = $this->creerBoutiqueAvecVendeur($pays, ['taux_commission' => 12]);
        ['variante' => $varianteA] = $this->creerProduitAvecVariante($boutiqueA, $categorie, ['prix_ttc_cfa' => 12_000], ['prix_ttc_cfa' => null, 'stock' => 10]);

        ['boutique' => $boutiqueB, 'utilisateur' => $vendeurB] = $this->creerBoutiqueAvecVendeur($pays, [
            'taux_commission' => 10, 'numero_fiscal' => 'IFU-000456', 'regime_fiscal' => 'reel',
        ]);
        ['variante' => $varianteB] = $this->creerProduitAvecVariante($boutiqueB, $categorie, ['prix_ttc_cfa' => 20_000], ['prix_ttc_cfa' => null, 'stock' => 10]);

        $this->postJson('/api/commandes', [
            'pays_id'       => $pays->id,
            'articles'      => [
                ['variante_id' => $varianteA->id, 'quantite' => 1],
                ['variante_id' => $varianteB->id, 'quantite' => 1],
            ],
            'nom' => 'Client Test', 'telephone' => '22670001122',
            'ville_id' => $ville->id, 'quartier' => 'Zone 1',
            'mode_paiement' => 'especes_livraison',
        ])->assertCreated();

        $sousCommandes = SousCommande::orderBy('id')->get();
        $this->assertCount(2, $sousCommandes);

        $encaisse = 0;

        foreach ([[$boutiqueA, $vendeurA], [$boutiqueB, $vendeurB]] as [$boutique, $vendeur]) {
            $sc = $sousCommandes->firstWhere('boutique_id', $boutique->id);

            $reponse = $this->actingAs($vendeur, 'sanctum')
                ->postJson("/api/vendeur/commandes/{$sc->id}/expedier", []);
            $reponse->assertOk();

            $montantDu = $sc->fresh()->montant_articles_ttc_cfa + $sc->fresh()->frais_livraison_cfa;
            $encaisse += $montantDu;

            $this->actingAs($vendeur, 'sanctum')
                ->postJson("/api/vendeur/commandes/{$sc->id}/livrer", [
                    'code_livraison'    => $sc->fresh()->expedition->code_livraison,
                    'montant_percu_cfa' => $montantDu,
                ])->assertOk();
        }

        $sousCommandes = $sousCommandes->fresh();
        $duAuxBoutiques = (int) $sousCommandes->sum('montant_net_cfa');
        $commissions    = (int) $sousCommandes->sum('commission_cfa');
        $retenues       = (int) $sousCommandes->sum('retenue_source_cfa');
        $livraison      = (int) $sousCommandes->sum('frais_livraison_cfa');

        $this->assertSame($encaisse, $duAuxBoutiques + $commissions + $retenues + $livraison);

        // Et le grand livre doit refléter exactement le net dû à chaque boutique.
        foreach ($sousCommandes as $sc) {
            $this->assertSame(
                $sc->montant_net_cfa,
                app(\App\Services\GrandLivreService::class)->solde($sc->boutique)
            );
        }
    }
}
