<?php

namespace Tests\Feature\Api;

use App\Models\AgentRemiseBoutique;
use App\Models\MouvementCompte;
use App\Models\RetenueSource;
use App\Models\TransactionPaiement;
use App\Services\GrandLivreService;
use Illuminate\Support\Facades\DB;
use App\Models\Notification;
use App\Models\SousCommande;
use App\Models\VarianteProduit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

/**
 * Échec de remise, notification du livreur à l'affectation, historique et
 * tableau de bord — le parcours livreur au-delà du simple « livrer ».
 */
class LivreurEchecTest extends TestCase
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

        $livreur = $this->creerUtilisateur($pays, ['role' => 'livreur', 'ville_id' => $ville->id, 'telephone' => '22670009900']);
        AgentRemiseBoutique::create([
            'boutique_id' => $boutique->id, 'livreur_id' => $livreur->id,
            'statut' => 'actif', 'autorise_livraison' => true,
            'invite_par_utilisateur_id' => $vendeur->id, 'accepte_le' => now(),
        ]);

        $this->actingAs($vendeur, 'sanctum')
            ->postJson("/api/vendeur/commandes/{$sousCommande->id}/expedier", ['livreur_id' => $livreur->id])
            ->assertOk();

        return compact('pays', 'boutique', 'vendeur', 'livreur', 'variante', 'sousCommande');
    }

    public function test_le_livreur_est_notifie_a_laffectation(): void
    {
        ['livreur' => $livreur] = $this->creerContexte();

        $this->assertDatabaseHas('notifications', [
            'telephone' => $livreur->telephone, 'canal' => 'sms',
        ]);

        $notif = Notification::where('telephone', $livreur->telephone)->firstOrFail();
        $this->assertStringContainsString('livraison', $notif->corps_envoye);
    }

    public function test_echec_sous_le_plafond_laisse_la_livraison_active(): void
    {
        ['livreur' => $livreur, 'sousCommande' => $sc] = $this->creerContexte();

        $reponse = $this->actingAs($livreur, 'sanctum')
            ->postJson("/api/livreur/commandes/{$sc->id}/echec", ['motif' => 'absent']);

        $reponse->assertOk()->assertJson(['statut' => 'en_cours']);

        $fraiche = $sc->fresh(['expedition']);
        $this->assertSame('expediee', $fraiche->statut);
        $this->assertSame('en_cours', $fraiche->expedition->statut);

        // Toujours affectée au livreur : elle doit rester dans sa liste de travail.
        $this->actingAs($livreur, 'sanctum')->getJson('/api/livreur/commandes')
            ->assertJsonFragment(['id' => $sc->id]);
    }

    /** Les déclarations d'échec doivent être espacées : on vieillit la précédente. */
    private function vieillirDerniersEchecs(SousCommande $sc): void
    {
        DB::table('evenements_commande')->where('sous_commande_id', $sc->id)
            ->where('type', 'livraison_echouee')
            ->update(['cree_le' => DB::raw('NOW() - INTERVAL 3 HOUR')]);
    }

    public function test_deux_echecs_trop_rapproches_sont_refuses(): void
    {
        ['livreur' => $livreur, 'sousCommande' => $sc] = $this->creerContexte();

        $this->actingAs($livreur, 'sanctum')->postJson("/api/livreur/commandes/{$sc->id}/echec", ['motif' => 'absent'])->assertOk();
        $this->actingAs($livreur, 'sanctum')->postJson("/api/livreur/commandes/{$sc->id}/echec", ['motif' => 'absent'])
            ->assertStatus(422);

        $this->assertSame(1, $sc->evenements()->where('type', 'livraison_echouee')->count());
    }

    public function test_echec_au_plafond_met_en_retour_sans_rembourser(): void
    {
        ['livreur' => $livreur, 'sousCommande' => $sc, 'variante' => $variante] = $this->creerContexte();
        $stockAvant = $variante->fresh()->stock;

        foreach (['absent', 'absent'] as $motif) {
            $this->actingAs($livreur, 'sanctum')->postJson("/api/livreur/commandes/{$sc->id}/echec", ['motif' => $motif])->assertOk();
            $this->vieillirDerniersEchecs($sc);
        }
        $reponse = $this->actingAs($livreur, 'sanctum')
            ->postJson("/api/livreur/commandes/{$sc->id}/echec", ['motif' => 'colis_refuse']);

        $reponse->assertOk()->assertJson(['statut' => 'retour_expediteur']);

        // Le livreur seul ne rembourse rien et ne remet rien en stock.
        $fraiche = $sc->fresh(['expedition']);
        $this->assertSame('retour_expediteur', $fraiche->expedition->statut);
        $this->assertSame('sequestre', $fraiche->etat_fonds->value);
        $this->assertSame($stockAvant, $variante->fresh()->stock);

        // Terminal : un quatrième échec ne doit plus rien changer.
        $this->vieillirDerniersEchecs($sc);
        $this->actingAs($livreur, 'sanctum')
            ->postJson("/api/livreur/commandes/{$sc->id}/echec", ['motif' => 'absent'])
            ->assertStatus(422);
    }

    public function test_la_boutique_cloture_le_retour_rembourse_et_contrepasse(): void
    {
        ['livreur' => $livreur, 'vendeur' => $vendeur, 'boutique' => $boutique,
         'sousCommande' => $sc, 'variante' => $variante] = $this->creerContexte();
        $stockAvant = $variante->fresh()->stock;
        $grandLivre = app(GrandLivreService::class);
        $this->assertGreaterThan(0, $grandLivre->solde($boutique));

        // Pas de retour déclaré : rien à clôturer.
        $this->actingAs($vendeur, 'sanctum')
            ->postJson("/api/vendeur/commandes/{$sc->id}/confirmer-retour")->assertStatus(422);

        foreach (['absent', 'absent', 'colis_refuse'] as $motif) {
            $this->actingAs($livreur, 'sanctum')->postJson("/api/livreur/commandes/{$sc->id}/echec", ['motif' => $motif])->assertOk();
            $this->vieillirDerniersEchecs($sc);
        }

        $this->actingAs($vendeur, 'sanctum')
            ->postJson("/api/vendeur/commandes/{$sc->id}/confirmer-retour")->assertOk();

        $fraiche = $sc->fresh();
        $this->assertSame('rembourse', $fraiche->etat_fonds->value);
        $this->assertSame('retournee', $fraiche->statut);
        $this->assertSame($stockAvant + 2, $variante->fresh()->stock);

        // Grand livre : la vente, la commission et la retenue sont
        // contrepassées — la boutique ne doit plus rien toucher, et la
        // plateforme ne garde pas de commission sur une vente annulée.
        $this->assertSame(0, $grandLivre->solde($boutique));
        $this->assertSame(0, (int) MouvementCompte::whereNull('boutique_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN sens='credit' THEN montant_cfa ELSE -montant_cfa END),0) s")->value('s'));
        $this->assertSame(0, RetenueSource::where('sous_commande_id', $sc->id)->where('statut', 'a_reverser')->count());

        // L'argent à rendre au client est dans la file de remboursement.
        $this->assertDatabaseHas('transactions_paiement', [
            'commande_id' => $sc->commande_id, 'sens' => 'remboursement',
            'montant_cfa' => $sc->montant_articles_ttc_cfa + $sc->frais_livraison_cfa,
            'statut' => 'initiee',
        ]);

        // Rejouer la clôture ne rembourse pas deux fois.
        $this->actingAs($vendeur, 'sanctum')
            ->postJson("/api/vendeur/commandes/{$sc->id}/confirmer-retour")->assertStatus(422);
        $this->assertSame(1, TransactionPaiement::where('sens', 'remboursement')->count());
    }

    public function test_un_autre_livreur_ne_peut_pas_signaler_lechec(): void
    {
        ['pays' => $pays, 'sousCommande' => $sc] = $this->creerContexte();
        $intrus = $this->creerUtilisateur($pays, ['role' => 'livreur']);

        $this->actingAs($intrus, 'sanctum')
            ->postJson("/api/livreur/commandes/{$sc->id}/echec", ['motif' => 'absent'])
            ->assertStatus(403);
    }

    public function test_historique_visible_par_filtre_de_statut_apres_livraison(): void
    {
        ['livreur' => $livreur, 'sousCommande' => $sc] = $this->creerContexte();
        $code = $sc->fresh()->expedition->code_livraison;

        $this->actingAs($livreur, 'sanctum')
            ->postJson("/api/livreur/commandes/{$sc->id}/livrer", ['code_livraison' => $code])
            ->assertOk();

        // Par défaut (travail du jour) : n'apparaît plus.
        $this->actingAs($livreur, 'sanctum')->getJson('/api/livreur/commandes')
            ->assertJsonMissing(['id' => $sc->id]);

        // Filtre historique : réapparaît.
        $this->actingAs($livreur, 'sanctum')->getJson('/api/livreur/commandes?statut[]=livree')
            ->assertJsonFragment(['id' => $sc->id]);
    }

    public function test_tableau_de_bord_compte_les_livraisons_du_jour(): void
    {
        ['livreur' => $livreur, 'sousCommande' => $sc] = $this->creerContexte();
        $code = $sc->fresh()->expedition->code_livraison;
        $this->actingAs($livreur, 'sanctum')->postJson("/api/livreur/commandes/{$sc->id}/livrer", ['code_livraison' => $code]);

        $reponse = $this->actingAs($livreur, 'sanctum')->getJson('/api/livreur/tableau-de-bord');

        $reponse->assertOk()->assertJsonPath('compteurs.livrees_aujourdhui', 1);
    }
}
