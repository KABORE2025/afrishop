<?php

namespace Tests\Unit\Services;

use App\Models\Commande;
use App\Services\GrandLivreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

class GrandLivreServiceTest extends TestCase
{
    use RefreshDatabase, CreeDonneesTrait;

    public function test_enregistrer_vente_ecrit_vente_commission_et_retenue(): void
    {
        $pays = $this->creerPays();
        ['boutique' => $boutique] = $this->creerBoutiqueAvecVendeur($pays);

        $commande = Commande::create([
            'reference'        => 'BF-CMD-2026-000001',
            'pays_id'          => $pays->id,
            'client_nom'       => 'Client Test',
            'client_telephone' => '22670000000',
            'quartier'         => 'Zone 1',
            'mode_paiement'    => 'especes_livraison',
        ]);

        $sc = $commande->sousCommandes()->create([
            'boutique_id'             => $boutique->id,
            'reference'               => 'BF-CMD-2026-000001-' . $boutique->code,
            'montant_articles_ttc_cfa'=> 24_000,
            'taux_commission_pct'     => 12,
            'commission_cfa'          => 2_880,
            'taux_retenue_source_pct' => 25,
            'retenue_source_cfa'      => 6_000,
            'montant_net_cfa'         => 24_000 - 2_880 - 6_000,
        ]);

        $service = app(GrandLivreService::class);
        $service->enregistrerVente($sc);

        $this->assertDatabaseHas('mouvements_compte', [
            'boutique_id' => $boutique->id, 'type' => 'vente', 'sens' => 'credit', 'montant_cfa' => 24_000,
        ]);
        $this->assertDatabaseHas('mouvements_compte', [
            'boutique_id' => $boutique->id, 'type' => 'commission', 'sens' => 'debit', 'montant_cfa' => 2_880,
        ]);
        $this->assertDatabaseHas('mouvements_compte', [
            'boutique_id' => null, 'type' => 'commission', 'sens' => 'credit', 'montant_cfa' => 2_880,
        ]);
        $this->assertDatabaseHas('mouvements_compte', [
            'boutique_id' => $boutique->id, 'type' => 'retenue_source', 'sens' => 'debit', 'montant_cfa' => 6_000,
        ]);

        // 24 000 − 2 880 (commission) − 6 000 (retenue) = 15 120.
        $this->assertSame(15_120, $service->solde($boutique));
    }

    public function test_contrepasser_annule_le_mouvement_et_refuse_le_double(): void
    {
        $pays = $this->creerPays();
        ['boutique' => $boutique] = $this->creerBoutiqueAvecVendeur($pays);
        $service = app(GrandLivreService::class);

        $mouvement = $service->ecrire($boutique->id, 'ajustement', 'credit', 1_000, 'test', 1, 'Test');
        $contrepassation = $service->contrepasser($mouvement, 'Erreur de saisie');

        $this->assertSame(0, $service->solde($boutique));
        $this->assertSame('debit', $contrepassation->sens);
        $this->assertSame($mouvement->id, $contrepassation->annule_mouvement_id);

        $this->expectException(RuntimeException::class);
        $service->contrepasser($mouvement, 'Deuxième tentative — doit échouer');
    }

    public function test_ecrire_refuse_un_montant_nul_ou_negatif(): void
    {
        $pays = $this->creerPays();
        ['boutique' => $boutique] = $this->creerBoutiqueAvecVendeur($pays);

        $this->expectException(RuntimeException::class);
        app(GrandLivreService::class)->ecrire($boutique->id, 'ajustement', 'credit', 0, 'test', 1, 'Montant nul');
    }
}
