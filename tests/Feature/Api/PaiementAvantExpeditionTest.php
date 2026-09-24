<?php

namespace Tests\Feature\Api;

use App\Models\Commande;
use App\Models\MouvementCompte;
use App\Models\SousCommande;
use App\Models\TransactionPaiement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

/**
 * Revue du 22/09 : le paiement Mobile Money se fait TOUJOURS avant la
 * livraison. Ces tests verrouillent le circuit de l'argent :
 *   · rien ne part tant que le prestataire n'a pas confirmé ;
 *   · un paiement abandonné expire et rend le stock ;
 *   · un encaissement tardif ou en double n'est jamais perdu.
 */
class PaiementAvantExpeditionTest extends TestCase
{
    use CreeDonneesTrait, RefreshDatabase;

    private function creerCommande(string $initiation = 'en_attente', string $mode = 'domicile'): array
    {
        config()->set('afrishop.psp.fake.initiation', $initiation);

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
            'mode_livraison' => $mode,
            'mode_paiement' => 'mobile_money',
        ])->assertSuccessful();

        $commande = Commande::firstOrFail();
        $sc = SousCommande::firstOrFail();
        $tx = TransactionPaiement::where('sens', 'encaissement')->firstOrFail();

        return compact('pays', 'ville', 'boutique', 'vendeur', 'variante', 'commande', 'sc', 'tx');
    }

    private function webhook(TransactionPaiement $tx, string $statut): void
    {
        $this->postJson('/api/webhooks/paiement/fake', [
            'reference_externe' => $tx->reference_externe, 'statut' => $statut,
        ])->assertOk();
    }

    private function vieillir(Commande $commande, int $minutes): void
    {
        DB::table('commandes')->where('id', $commande->id)
            ->update(['cree_le' => now()->subMinutes($minutes)]);
        DB::table('transactions_paiement')->where('commande_id', $commande->id)
            ->update(['initiee_le' => now()->subMinutes($minutes)]);
    }

    public function test_une_commande_non_payee_ne_peut_etre_ni_expediee_ni_preparee(): void
    {
        ['vendeur' => $vendeur, 'sc' => $sc] = $this->creerCommande('en_attente');

        $this->assertSame('attente_encaissement', $sc->etat_fonds->value);
        $this->assertSame(0, MouvementCompte::count(), 'Aucune écriture avant encaissement.');

        $this->actingAs($vendeur, 'sanctum')
            ->postJson("/api/vendeur/commandes/{$sc->id}/expedier", ['livreur_id' => 1])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Paiement non confirmé : ne préparez ni n’expédiez ce colis tant que le client n’a pas payé. La commande apparaîtra comme payée dès la confirmation.']);
    }

    public function test_le_retrait_boutique_non_paye_est_bloque(): void
    {
        ['vendeur' => $vendeur, 'sc' => $sc] = $this->creerCommande('en_attente', 'retrait_boutique');

        $this->actingAs($vendeur, 'sanctum')
            ->postJson("/api/vendeur/commandes/{$sc->id}/preparer-retrait")
            ->assertStatus(422);

        $this->assertNull($sc->fresh()->code_retrait);
    }

    public function test_la_confirmation_du_prestataire_met_en_sequestre_et_ecrit_le_grand_livre(): void
    {
        ['sc' => $sc, 'tx' => $tx, 'commande' => $commande] = $this->creerCommande('en_attente');

        $this->webhook($tx, 'reussie');
        $this->webhook($tx, 'reussie'); // rejoué : aucun effet

        $this->assertSame('encaisse', $commande->fresh()->statut_paiement);
        $this->assertSame('sequestre', $sc->fresh()->etat_fonds->value);
        $this->assertSame(1, MouvementCompte::where('type', 'vente')->count());
    }

    public function test_un_paiement_abandonne_expire_et_rend_le_stock(): void
    {
        ['sc' => $sc, 'tx' => $tx, 'commande' => $commande, 'variante' => $variante] = $this->creerCommande('en_attente');
        $this->assertSame(8, $variante->fresh()->stock);

        config()->set('afrishop.psp.fake.verification', 'indetermine');

        // Sous le délai : rien ne bouge.
        $this->vieillir($commande, 10);
        $this->artisan('afrishop:verifier-paiements')->assertSuccessful();
        $this->assertSame('attente', $commande->fresh()->statut_paiement);

        // Au-delà : annulée, stock rendu, tentative expirée.
        $this->vieillir($commande, 45);
        $this->artisan('afrishop:verifier-paiements')->assertSuccessful();

        $this->assertSame('echoue', $commande->fresh()->statut_paiement);
        $this->assertSame('impaye', $sc->fresh()->etat_fonds->value);
        $this->assertSame('annulee', $sc->fresh()->statut);
        $this->assertSame('expiree', $tx->fresh()->statut);
        $this->assertSame(10, $variante->fresh()->stock);

        // Le client paie malgré tout plus tard : la commande n'est pas
        // ressuscitée, aucune vente n'est écrite, l'argent part en
        // remboursement.
        $this->webhook($tx->fresh(), 'reussie');

        $this->assertSame('echoue', $commande->fresh()->statut_paiement);
        $this->assertSame(0, MouvementCompte::count());
        $this->assertDatabaseHas('transactions_paiement', [
            'commande_id' => $commande->id, 'sens' => 'remboursement', 'statut' => 'initiee',
            'montant_cfa' => $commande->total_a_payer_cfa,
        ]);
        $this->assertSame(10, $variante->fresh()->stock);
    }

    /**
     * Revue du 24/09 : une commande antérieure au correctif a pu partir
     * sans paiement. L'expiration ne doit ni l'annuler ni la remettre en
     * stock — l'article n'est plus sur l'étagère.
     */
    public function test_un_colis_deja_parti_n_est_ni_annule_ni_remis_en_stock(): void
    {
        ['sc' => $sc, 'commande' => $commande, 'variante' => $variante] = $this->creerCommande('en_attente');
        $sc->update(['statut' => 'expediee', 'expedie_le' => now()]);   // expédié avant le correctif
        config()->set('afrishop.psp.fake.verification', 'indetermine');

        $this->vieillir($commande, 45);
        $this->artisan('afrishop:verifier-paiements')->assertSuccessful();

        $frais = $sc->fresh();
        $this->assertSame('expediee', $frais->statut, 'Un colis parti ne devient pas « annulé ».');
        $this->assertSame('attente_encaissement', $frais->etat_fonds->value);
        $this->assertSame(8, $variante->fresh()->stock, 'L’article parti ne revient pas en stock.');
        $this->assertDatabaseHas('evenements_commande', [
            'sous_commande_id' => $sc->id, 'type' => 'paiement_absent_colis_parti',
        ]);
    }

    public function test_un_prestataire_qui_refuse_annule_la_commande(): void
    {
        ['commande' => $commande, 'variante' => $variante] = $this->creerCommande('en_attente');
        config()->set('afrishop.psp.fake.verification', 'refuse');
        $this->vieillir($commande, 5);

        $this->artisan('afrishop:verifier-paiements')->assertSuccessful();

        $this->assertSame('echoue', $commande->fresh()->statut_paiement);
        $this->assertSame(10, $variante->fresh()->stock);
    }

    public function test_une_relance_recente_protege_la_commande_de_lexpiration(): void
    {
        ['commande' => $commande] = $this->creerCommande('en_attente');
        config()->set('afrishop.psp.fake.verification', 'indetermine');
        $this->vieillir($commande, 45);

        // Le client relance : nouvelle tentative, toute fraîche.
        $this->post("/commande/{$commande->reference}/payer")->assertRedirect();
        $this->assertSame(2, TransactionPaiement::where('sens', 'encaissement')->count());

        $this->artisan('afrishop:verifier-paiements', ['--age' => 0])->assertSuccessful();

        $this->assertSame('attente', $commande->fresh()->statut_paiement);
    }

    public function test_un_echec_de_la_premiere_tentative_n_annule_pas_la_relance(): void
    {
        ['commande' => $commande, 'tx' => $premiere] = $this->creerCommande('en_attente');
        $this->post("/commande/{$commande->reference}/payer")->assertRedirect();

        $this->webhook($premiere, 'echouee');

        $this->assertSame('attente', $commande->fresh()->statut_paiement);
    }

    public function test_un_double_paiement_cree_un_remboursement(): void
    {
        ['commande' => $commande, 'tx' => $premiere] = $this->creerCommande('en_attente');
        $this->post("/commande/{$commande->reference}/payer")->assertRedirect();
        $seconde = TransactionPaiement::where('sens', 'encaissement')->latest('id')->first();

        $this->webhook($premiere, 'reussie');
        $this->webhook($seconde, 'reussie');

        $this->assertSame(1, MouvementCompte::where('type', 'vente')->count());
        $this->assertSame(1, TransactionPaiement::where('sens', 'remboursement')->count());
    }

    public function test_une_commande_annulee_ne_peut_plus_etre_payee(): void
    {
        ['commande' => $commande, 'tx' => $tx] = $this->creerCommande('en_attente');
        $this->webhook($tx, 'echouee');
        $this->assertSame('echoue', $commande->fresh()->statut_paiement);

        $this->post("/commande/{$commande->reference}/payer")
            ->assertRedirect(route('commande.confirmee', $commande->reference))
            ->assertSessionHas('erreur');

        $this->assertSame(1, TransactionPaiement::where('sens', 'encaissement')->count());
    }
}
