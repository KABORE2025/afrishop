<?php

namespace Tests\Feature\Console;

use App\Models\Commande;
use App\Models\PrestatairePaiement;
use App\Models\TransactionPaiement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

class CronCommandsTest extends TestCase
{
    use RefreshDatabase, CreeDonneesTrait;

    public function test_preparer_reversements_regroupe_les_ventes_liberees_dune_boutique(): void
    {
        $pays = $this->creerPays();
        ['boutique' => $boutique] = $this->creerBoutiqueAvecVendeur($pays, [], ['kyc_niveau' => 'complet']);

        $commande = Commande::create([
            'reference'        => 'BF-CMD-2026-900001', 'pays_id' => $pays->id,
            'client_nom'       => 'Client', 'client_telephone' => '22670000000',
            'quartier'         => 'Zone 1', 'mode_paiement' => 'especes_livraison',
        ]);

        $sc = $commande->sousCommandes()->create([
            'boutique_id'              => $boutique->id, 'reference' => 'REF-900001',
            'statut'                   => 'livree', 'etat_fonds' => 'reverse',
            'montant_articles_ttc_cfa' => 20_000, 'taux_commission_pct' => 12,
            'commission_cfa'           => 2_400, 'montant_net_cfa' => 17_600,
            'livre_le'                 => now()->subDays(2),
        ]);

        $this->artisan('afrishop:preparer-reversements')->assertExitCode(0);

        $this->assertDatabaseHas('reversements', [
            'boutique_id' => $boutique->id, 'statut' => 'a_payer', 'montant_net_cfa' => 17_600,
        ]);
        $this->assertDatabaseHas('reversement_lignes', ['sous_commande_id' => $sc->id]);
        $this->assertDatabaseHas('mouvements_compte', [
            'boutique_id' => $boutique->id, 'type' => 'reversement', 'sens' => 'debit', 'montant_cfa' => 17_600,
        ]);
    }

    public function test_reconcilier_psp_calcule_le_volet_local_du_jour(): void
    {
        $pays = $this->creerPays();
        $prestataire = PrestatairePaiement::create(['code' => 'fake', 'nom' => 'Fake PSP', 'actif' => true]);

        $commande = Commande::create([
            'reference'        => 'BF-CMD-2026-900002', 'pays_id' => $pays->id,
            'client_nom'       => 'Client', 'client_telephone' => '22670000000',
            'quartier'         => 'Zone 1', 'mode_paiement' => 'mobile_money',
        ]);

        TransactionPaiement::create([
            'commande_id'   => $commande->id, 'prestataire_id' => $prestataire->id,
            'sens'          => 'encaissement', 'montant_cfa' => 12_000, 'statut' => 'reussie',
            'finalisee_le'  => now()->subDay(),
        ]);

        $this->artisan('afrishop:reconcilier-psp')->assertExitCode(0);

        $this->assertDatabaseHas('reconciliations_psp', [
            'prestataire_id' => $prestataire->id, 'nb_operations_local' => 1,
            'montant_local_cfa' => 12_000, 'statut' => 'en_cours',
        ]);
    }

    public function test_reconcilier_psp_ne_recalcule_pas_un_arrete_deja_rapproche(): void
    {
        $pays = $this->creerPays();
        $prestataire = PrestatairePaiement::create(['code' => 'fake', 'nom' => 'Fake PSP', 'actif' => true]);

        app(\App\Services\ReconciliationPspService::class)->enregistrerLocal($prestataire->id, now()->subDay());
        $arrete = \App\Models\ReconciliationPsp::first();
        app(\App\Services\ReconciliationPspService::class)->rapprocher($arrete, 0, 0);

        $this->artisan('afrishop:reconcilier-psp')->assertExitCode(0);

        $this->assertSame('conforme', $arrete->fresh()->statut);
    }
}
