<?php

namespace Tests\Feature;

use App\Models\Avis;
use App\Models\Retour;
use App\Models\SousCommande;
use App\Services\SequestreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

/**
 * Gestes du client (annuler, retourner, noter) et pages de la console
 * (réglages, journal, avis).
 */
class ClientEtConsoleTest extends TestCase
{
    use CreeDonneesTrait, RefreshDatabase;

    private const TEL = '22670001122';

    /** Commande payée en retrait boutique, 2 articles. $remis : code validé. */
    private function commande(bool $remis = false): array
    {
        $pays = $this->creerPays();
        $ville = $this->creerVille($pays);
        $this->creerZoneLivraison($pays, $ville);
        $categorie = $this->creerCategorie();
        ['boutique' => $b, 'utilisateur' => $vendeur] = $this->creerBoutiqueAvecVendeur($pays, ['ville_id' => $ville->id]);
        ['variante' => $v, 'produit' => $p] = $this->creerProduitAvecVariante($b, $categorie, [], ['stock' => 10, 'prix_ttc_cfa' => 5_000]);

        $this->postJson('/api/commandes', [
            'pays_id' => $pays->id, 'articles' => [['variante_id' => $v->id, 'quantite' => 2]],
            'nom' => 'Awa Ouédraogo', 'telephone' => self::TEL, 'ville_id' => $ville->id, 'quartier' => 'Z',
            'mode_livraison' => 'retrait_boutique', 'mode_paiement' => 'mobile_money',
        ])->assertCreated();
        $sc = SousCommande::with('commande')->firstOrFail();

        if ($remis) {
            $this->actingAs($vendeur, 'sanctum')->postJson("/api/vendeur/commandes/{$sc->id}/preparer-retrait")->assertOk();
            $this->actingAs($vendeur, 'sanctum')->postJson("/api/vendeur/commandes/{$sc->id}/confirmer-retrait", [
                'code_retrait' => $sc->fresh()->code_retrait])->assertOk();
        }

        return ['sc' => $sc->fresh('commande'), 'vendeur' => $vendeur, 'variante' => $v, 'produit' => $p, 'pays' => $pays];
    }

    private function url(SousCommande $sc, string $geste): string
    {
        return "/commande/{$sc->commande->reference}/colis/{$sc->reference}/{$geste}";
    }

    // -----------------------------------------------------------------
    //  ANNULATION
    // -----------------------------------------------------------------

    public function test_le_client_annule_un_colis_pas_encore_prepare(): void
    {
        ['sc' => $sc, 'variante' => $v] = $this->commande();
        $this->assertSame(8, $v->fresh()->stock);

        $this->post($this->url($sc, 'annuler'), ['telephone' => '70999999'])->assertSessionHas('erreur');
        $this->post($this->url($sc, 'annuler'), ['telephone' => self::TEL])->assertSessionHas('succes');

        $frais = $sc->fresh();
        $this->assertSame('annulee', $frais->statut);
        $this->assertSame('rembourse', $frais->etat_fonds->value);
        $this->assertSame(10, $v->fresh()->stock);
    }

    public function test_un_colis_prepare_ne_s_annule_plus(): void
    {
        ['sc' => $sc, 'vendeur' => $vendeur] = $this->commande();
        $this->actingAs($vendeur, 'sanctum')->postJson("/api/vendeur/commandes/{$sc->id}/preparer-retrait")->assertOk();

        $this->post($this->url($sc, 'annuler'), ['telephone' => self::TEL])->assertSessionHas('erreur');
        $this->assertSame('sequestre', $sc->fresh()->etat_fonds->value);
    }

    // -----------------------------------------------------------------
    //  RETOUR
    // -----------------------------------------------------------------

    public function test_retour_complet_demande_acceptation_reception_remboursement(): void
    {
        ['sc' => $sc, 'vendeur' => $vendeur, 'variante' => $v] = $this->commande(remis: true);
        $this->assertSame(8, $v->fresh()->stock);

        $this->post($this->url($sc, 'retour'), ['telephone' => self::TEL, 'motif' => 'taille_incorrecte'])->assertSessionHas('succes');
        $retour = Retour::firstOrFail();
        $this->assertSame('client', $retour->frais_a_la_charge);

        // Pendant la demande, l'argent est gelé même après les 72 h.
        $this->travel(80)->hours();
        app(SequestreService::class)->libererLesEchues();
        $this->assertSame('sequestre', $sc->fresh()->etat_fonds->value);

        // Rien n'est remboursé tant que la boutique n'a pas l'article en main.
        $this->actingAs($vendeur, 'sanctum')->postJson("/api/vendeur/retours/{$retour->id}/recevoir")->assertStatus(422);
        $this->actingAs($vendeur, 'sanctum')->postJson("/api/vendeur/retours/{$retour->id}/accepter")->assertOk();
        $this->actingAs($vendeur, 'sanctum')->getJson('/api/vendeur/commandes')->assertJsonPath('data.0.retour.statut', 'accepte');
        $this->actingAs($vendeur, 'sanctum')->postJson("/api/vendeur/retours/{$retour->id}/recevoir")->assertOk();

        $this->assertSame('rembourse', $sc->fresh()->etat_fonds->value);
        $this->assertSame('retournee', $sc->fresh()->statut);
        $this->assertSame('rembourse', $retour->fresh()->statut);
        $this->assertSame(10, $v->fresh()->stock);
    }

    public function test_retour_refuse_puis_une_seule_demande_par_colis(): void
    {
        ['sc' => $sc, 'vendeur' => $vendeur, 'pays' => $pays] = $this->commande(remis: true);
        $this->post($this->url($sc, 'retour'), ['telephone' => self::TEL, 'motif' => 'ne_convient_pas']);
        $retour = Retour::firstOrFail();

        ['utilisateur' => $autre] = $this->creerBoutiqueAvecVendeur($pays);
        $this->actingAs($autre, 'sanctum')->postJson("/api/vendeur/retours/{$retour->id}/accepter")->assertForbidden();

        $this->actingAs($vendeur, 'sanctum')->postJson("/api/vendeur/retours/{$retour->id}/refuser", ['motif' => 'Article porté, étiquette retirée.'])->assertOk();
        $this->post($this->url($sc, 'retour'), ['telephone' => self::TEL, 'motif' => 'autre'])->assertSessionHas('erreur');
        $this->assertSame(1, Retour::count());

        // Refus : l'argent n'est plus gelé, il part à la fin du délai.
        $this->travel(80)->hours();
        app(SequestreService::class)->libererLesEchues();
        $this->assertSame('reverse', $sc->fresh()->etat_fonds->value);
    }

    // -----------------------------------------------------------------
    //  AVIS
    // -----------------------------------------------------------------

    public function test_avis_seulement_apres_reception_et_affiche_sur_la_fiche(): void
    {
        ['sc' => $sc, 'produit' => $p] = $this->commande();
        $avis = ['telephone' => self::TEL, 'produit_id' => $p->id, 'note' => 4, 'commentaire' => 'Très bon karité.'];

        $this->post($this->url($sc, 'avis'), $avis)->assertSessionHas('erreur');   // pas encore reçu
        $this->assertSame(0, Avis::count());

        ['sc' => $remis, 'produit' => $p2] = $this->commandeRemiseSupplementaire();
        $this->post($this->url($remis, 'avis'), [...$avis, 'produit_id' => $p2->id])->assertSessionHas('succes');
        $this->post($this->url($remis, 'avis'), [...$avis, 'produit_id' => $p2->id])->assertSessionHas('erreur');   // un seul

        $this->assertSame('Awa O.', Avis::firstOrFail()->auteur_affiche);
        $this->get("/p/{$p2->slug}")->assertOk()->assertSee('Très bon karité.')->assertSee('4,0/5');
    }

    public function test_l_admin_retire_un_avis(): void
    {
        ['sc' => $sc, 'produit' => $p, 'pays' => $pays] = $this->commande(remis: true);
        $this->post($this->url($sc, 'avis'), ['telephone' => self::TEL, 'produit_id' => $p->id, 'note' => 1, 'commentaire' => 'Appelez-moi au 70 00 00 00']);
        $avis = Avis::firstOrFail();
        $admin = $this->creerUtilisateur($pays, ['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/avis/{$avis->id}/retirer", ['motif' => 'Contient un numéro de téléphone.'])->assertOk();
        $this->get("/p/{$p->slug}")->assertOk()->assertDontSee('Appelez-moi');
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/avis?statut=rejete')->assertOk()->assertJsonCount(1, 'data');
    }

    // -----------------------------------------------------------------
    //  RÉGLAGES ET JOURNAL
    // -----------------------------------------------------------------

    public function test_l_admin_modifie_un_reglage_dans_ses_bornes_et_c_est_journalise(): void
    {
        $pays = $this->creerPays();
        $admin = $this->creerUtilisateur($pays, ['role' => 'admin']);
        $agent = $this->creerUtilisateur($pays, ['role' => 'agent']);
        $this->assertSame(3, (int) parametre('delai_confirmation_auto_jours', 3));

        $this->actingAs($agent, 'sanctum')->putJson('/api/admin/parametres/delai_confirmation_auto_jours', ['valeur' => 5, 'motif' => 'Essai par un agent.'])->assertForbidden();
        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/parametres/delai_confirmation_auto_jours', ['valeur' => 0, 'motif' => 'Faute de frappe.'])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/parametres/inconnu', ['valeur' => 1, 'motif' => 'Clé inexistante.'])->assertNotFound();

        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/parametres/delai_confirmation_auto_jours', ['valeur' => 5, 'motif' => 'Plus de temps pour vérifier.'])->assertOk();
        $this->assertSame(5, (int) parametre('delai_confirmation_auto_jours', 3), 'Le cache est bien vidé.');

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/journal')->assertOk()
            ->assertJsonPath('data.0.action', 'parametre_modifie')
            ->assertJsonPath('data.0.avant.valeur', '3')
            ->assertJsonPath('data.0.apres.valeur', '5');
    }

    public function test_les_nouvelles_pages_s_affichent(): void
    {
        foreach (['/vendeur/boutique', '/vendeur/etiquettes', '/console/boutiques', '/console/etiquettes',
                  '/console/reglages', '/console/journal', '/console/avis'] as $page) {
            $this->get($page)->assertOk();
        }
    }

    /** Deuxième commande, remise, d'un autre produit (boutique déjà créée). */
    private function commandeRemiseSupplementaire(): array
    {
        $sc0 = SousCommande::firstOrFail();
        $b = $sc0->boutique;
        $vendeur = $b->utilisateur;
        ['variante' => $v, 'produit' => $p] = $this->creerProduitAvecVariante($b, \App\Models\Categorie::firstOrFail(),
            ['nom' => 'Savon noir'], ['stock' => 5, 'prix_ttc_cfa' => 2_000]);

        $this->postJson('/api/commandes', [
            'pays_id' => $b->pays_id, 'articles' => [['variante_id' => $v->id, 'quantite' => 1]],
            'nom' => 'Awa Ouédraogo', 'telephone' => self::TEL, 'ville_id' => $b->ville_id, 'quartier' => 'Z',
            'mode_livraison' => 'retrait_boutique', 'mode_paiement' => 'mobile_money',
        ])->assertCreated();
        $sc = SousCommande::latest('id')->firstOrFail();
        $this->actingAs($vendeur, 'sanctum')->postJson("/api/vendeur/commandes/{$sc->id}/preparer-retrait")->assertOk();
        $this->actingAs($vendeur, 'sanctum')->postJson("/api/vendeur/commandes/{$sc->id}/confirmer-retrait", ['code_retrait' => $sc->fresh()->code_retrait])->assertOk();

        return ['sc' => $sc->fresh('commande'), 'produit' => $p];
    }
}
