<?php

namespace Tests\Feature\Api;

use App\Models\Litige;
use App\Models\Notification;
use App\Models\Retour;
use App\Models\SousCommande;
use App\Services\SequestreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

/**
 * Fenêtre de protection de 72 h et litiges.
 *
 * Le code de remise prouve que le colis a été remis, pas que son
 * contenu est conforme. Ces tests verrouillent :
 *   · l'argent reste en séquestre 72 h après la remise, code ou non ;
 *   · le client peut confirmer (paiement immédiat) ou signaler (gel) ;
 *   · la boutique donne sa version, une seule fois ;
 *   · l'admin peut ouvrir un litige pour un client qui a appelé.
 */
class LitigeTest extends TestCase
{
    use CreeDonneesTrait, RefreshDatabase;

    private const TELEPHONE = '22670001122';

    /** Une commande retirée en boutique, code validé : colis remis. */
    private function colisRemis(): array
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
            'nom' => 'Client Test', 'telephone' => self::TELEPHONE,
            'ville_id' => $ville->id, 'quartier' => 'Zone 1',
            'mode_livraison' => 'retrait_boutique',
            'mode_paiement' => 'mobile_money',
        ])->assertCreated();

        $sc = SousCommande::where('boutique_id', $boutique->id)->firstOrFail();
        $this->actingAs($vendeur, 'sanctum')->postJson("/api/vendeur/commandes/{$sc->id}/preparer-retrait")->assertOk();
        $this->actingAs($vendeur, 'sanctum')->postJson("/api/vendeur/commandes/{$sc->id}/confirmer-retrait", [
            'code_retrait' => $sc->fresh()->code_retrait,
        ])->assertOk();

        $admin = $this->creerUtilisateur($pays, ['role' => 'admin']);

        return ['sc' => $sc->fresh(['commande']), 'vendeur' => $vendeur, 'admin' => $admin, 'pays' => $pays];
    }

    private function urlColis(SousCommande $sc, string $geste): string
    {
        return "/commande/{$sc->commande->reference}/colis/{$sc->reference}/{$geste}";
    }

    private function signaler(SousCommande $sc, array $surcharge = [])
    {
        return $this->post($this->urlColis($sc, 'signaler'), [
            'telephone' => '70001122', 'motif' => 'endommage',
            'description' => 'Le produit était cassé à l’ouverture du carton.',
            ...$surcharge,
        ]);
    }

    // -----------------------------------------------------------------
    //  1. FENÊTRE DE PROTECTION
    // -----------------------------------------------------------------

    public function test_les_fonds_restent_sequestres_72_h_apres_la_remise(): void
    {
        ['sc' => $sc] = $this->colisRemis();
        $sequestre = app(SequestreService::class);

        $this->assertSame('sequestre', $sc->etat_fonds->value);

        $this->travel(71)->hours();
        $sequestre->libererLesEchues();
        $this->assertSame('sequestre', $sc->fresh()->etat_fonds->value);

        $this->travel(2)->hours();
        $sequestre->libererLesEchues();
        $this->assertSame('reverse', $sc->fresh()->etat_fonds->value);
    }

    public function test_la_page_client_propose_les_deux_gestes_pendant_le_delai(): void
    {
        ['sc' => $sc] = $this->colisRemis();

        $this->get("/commande/{$sc->commande->reference}")
            ->assertOk()->assertSee('Tout est conforme')->assertSee('Signaler un problème');

        $this->travel(73)->hours();

        $this->get("/commande/{$sc->commande->reference}")
            ->assertOk()->assertDontSee('Signaler un problème')->assertSee('Délai de vérification écoulé');
    }

    // -----------------------------------------------------------------
    //  2. LE CLIENT
    // -----------------------------------------------------------------

    public function test_le_client_confirme_et_la_boutique_est_payee_tout_de_suite(): void
    {
        ['sc' => $sc] = $this->colisRemis();

        $this->post($this->urlColis($sc, 'confirmer'), ['telephone' => '+226 70 00 11 22'])
            ->assertRedirect()->assertSessionHas('succes');

        $frais = $sc->fresh();
        $this->assertNotNull($frais->confirme_par_client_le);
        $this->assertSame('reverse', $frais->etat_fonds->value);

        // Après confirmation, plus de signalement possible.
        $this->signaler($sc)->assertSessionHas('erreur');
        $this->assertSame(0, Litige::count());
    }

    public function test_un_mauvais_telephone_ne_permet_ni_de_confirmer_ni_de_signaler(): void
    {
        ['sc' => $sc] = $this->colisRemis();

        $this->post($this->urlColis($sc, 'confirmer'), ['telephone' => '70999999'])->assertSessionHas('erreur');
        $this->signaler($sc, ['telephone' => '70999999'])->assertSessionHas('erreur');

        $this->assertNull($sc->fresh()->confirme_par_client_le);
        $this->assertSame(0, Litige::count());
    }

    public function test_un_signalement_gele_les_fonds_et_previent_la_boutique(): void
    {
        ['sc' => $sc, 'vendeur' => $vendeur] = $this->colisRemis();

        $this->signaler($sc)->assertRedirect()->assertSessionHas('succes');

        $litige = Litige::firstOrFail();
        $this->assertSame('ouvert', $litige->statut);
        $this->assertSame('endommage', $litige->motif);
        $this->assertDatabaseHas('evenements_commande', [
            'sous_commande_id' => $sc->id, 'type' => 'litige_ouvert', 'auteur_type' => 'client',
        ]);
        $this->assertDatabaseHas('notifications', ['destinataire_id' => $vendeur->id, 'canal' => 'sms']);

        // Le délai passe : le litige gèle toujours l'argent.
        $this->travel(80)->hours();
        app(SequestreService::class)->libererLesEchues();
        $this->assertSame('sequestre', $sc->fresh()->etat_fonds->value);

        // Un second signalement n'ouvre pas un second litige.
        $this->travel(-80)->hours();
        $this->signaler($sc)->assertSessionHas('erreur');
        $this->assertSame(1, Litige::count());
    }

    public function test_signaler_apres_72_h_est_refuse(): void
    {
        ['sc' => $sc] = $this->colisRemis();
        $this->travel(73)->hours();

        $this->signaler($sc)->assertSessionHas('erreur');
        $this->assertSame(0, Litige::count());
    }

    // -----------------------------------------------------------------
    //  3. LA BOUTIQUE
    // -----------------------------------------------------------------

    public function test_la_boutique_voit_le_litige_et_repond_une_seule_fois(): void
    {
        ['sc' => $sc, 'vendeur' => $vendeur] = $this->colisRemis();
        $this->signaler($sc);
        $litige = Litige::firstOrFail();

        $this->actingAs($vendeur, 'sanctum')->getJson('/api/vendeur/commandes')
            ->assertOk()
            ->assertJsonPath('data.0.litige.reference', $litige->reference)
            ->assertJsonPath('data.0.litige.ouvert', true)
            ->assertJsonPath('data.0.litige.a_repondu', false);

        $this->actingAs($vendeur, 'sanctum')
            ->postJson("/api/vendeur/litiges/{$litige->id}/repondre", [
                'argument' => 'Produit contrôlé et photographié intact avant la remise au comptoir.',
            ])->assertOk();

        $litige->refresh();
        $this->assertSame('en_examen', $litige->statut);
        $this->assertNotNull($litige->argument_boutique);

        $this->actingAs($vendeur, 'sanctum')
            ->postJson("/api/vendeur/litiges/{$litige->id}/repondre", [
                'argument' => 'Je voudrais finalement modifier ma réponse précédente.',
            ])->assertStatus(422);
    }

    public function test_une_autre_boutique_ne_peut_pas_repondre(): void
    {
        ['sc' => $sc, 'pays' => $pays] = $this->colisRemis();
        $this->signaler($sc);
        ['utilisateur' => $autre] = $this->creerBoutiqueAvecVendeur($pays);

        $this->actingAs($autre, 'sanctum')
            ->postJson('/api/vendeur/litiges/' . Litige::firstOrFail()->id . '/repondre', [
                'argument' => 'Tentative de réponse par une boutique étrangère au litige.',
            ])->assertStatus(403);
    }

    // -----------------------------------------------------------------
    //  4. L'ADMIN
    // -----------------------------------------------------------------

    public function test_l_admin_ouvre_un_litige_meme_apres_le_delai_tant_que_l_argent_est_la(): void
    {
        ['sc' => $sc, 'admin' => $admin] = $this->colisRemis();
        $this->travel(73)->hours();   // le client a appelé à temps, le support traite tard

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/sous-commandes/{$sc->id}/litiges", [
                'motif' => 'non_conforme', 'description' => 'Appel du client : taille reçue différente de la commande.',
            ])->assertCreated()->assertJsonPath('sous_commande.id', $sc->id);

        $this->assertDatabaseHas('evenements_commande', [
            'sous_commande_id' => $sc->id, 'type' => 'litige_ouvert', 'auteur_type' => 'admin',
        ]);

        // La liste de la console charge la sous-commande du litige.
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/litiges')
            ->assertOk()->assertJsonPath('data.0.sous_commande.id', $sc->id);

        // Arbitrage en faveur de la boutique : les fonds partent.
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/litiges/' . Litige::firstOrFail()->id . '/arbitrer', [
                'sens' => 'boutique', 'resolution' => 'Photos de la boutique probantes, taille conforme.',
            ])->assertOk();
        $this->assertSame('reverse', $sc->fresh()->etat_fonds->value);
    }

    // -----------------------------------------------------------------
    //  5. PHOTOS
    // -----------------------------------------------------------------

    public function test_le_client_joint_des_photos_privees_visibles_par_lien_signe(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        ['sc' => $sc, 'admin' => $admin, 'vendeur' => $vendeur] = $this->colisRemis();

        $this->signaler($sc, ['photos' => [
            UploadedFile::fake()->image('ecran.jpg', 2000, 1500),
            UploadedFile::fake()->image('carton.jpg', 800, 600),
        ]])->assertSessionHas('succes');

        $litige = Litige::firstOrFail();
        $this->assertSame(2, $litige->photos()->count());

        // Rien dans le dossier public : ces photos montrent un domicile.
        $this->assertEmpty(Storage::disk('public')->allFiles());
        $this->assertNotEmpty(Storage::disk('local')->allFiles("litiges/{$litige->id}"));

        $url = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/litiges')
            ->assertOk()->assertJsonCount(2, 'data.0.client.photos')
            ->json('data.0.client.photos.0.grand');

        // La boutique voit les mêmes photos pour pouvoir répondre.
        $this->actingAs($vendeur, 'sanctum')->getJson('/api/vendeur/commandes')
            ->assertOk()->assertJsonCount(2, 'data.0.litige.photos');

        $this->get($url)->assertOk();

        // Sans signature, ou une fois le lien expiré : refusé.
        $media = $litige->photos()->first();
        $this->get("/litiges/photos/{$media->id}/grand")->assertForbidden();
        $this->travel(31)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_plus_de_deux_photos_est_refuse(): void
    {
        Storage::fake('local');
        ['sc' => $sc] = $this->colisRemis();

        $this->signaler($sc, ['photos' => array_map(
            fn ($i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, 3)
        )])->assertSessionHasErrors('photos');

        $this->assertSame(0, Litige::count());
    }

    public function test_un_signalement_sans_photo_reste_possible(): void
    {
        ['sc' => $sc, 'admin' => $admin] = $this->colisRemis();

        $this->signaler($sc)->assertSessionHas('succes');

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/litiges')
            ->assertOk()->assertJsonCount(0, 'data.0.client.photos');
    }

    // -----------------------------------------------------------------
    //  6. SMS DE DÉCISION
    // -----------------------------------------------------------------

    public function test_le_client_est_prevenu_par_sms_de_la_decision(): void
    {
        ['sc' => $sc, 'admin' => $admin] = $this->colisRemis();
        $this->signaler($sc);
        $litige = Litige::firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/litiges/{$litige->id}/arbitrer", [
                'sens' => 'client', 'resolution' => 'Photos probantes : produit cassé à la réception.',
            ])->assertOk();

        $sms = Notification::where('telephone', self::TELEPHONE)->get()
            ->first(fn ($n) => str_contains($n->corps_envoye, $litige->reference));

        $this->assertNotNull($sms, 'Le client doit recevoir un SMS de décision.');
        $this->assertStringContainsString('remboursé', $sms->corps_envoye);
        $this->assertSame('rembourse', $sc->fresh()->etat_fonds->value);
    }

    public function test_pas_de_sms_si_l_arbitrage_echoue(): void
    {
        ['sc' => $sc, 'admin' => $admin] = $this->colisRemis();
        $this->signaler($sc);
        $litige = Litige::firstOrFail();
        $sc->update(['statut' => 'expediee']);   // l'argent ne peut plus partir vers la boutique

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/litiges/{$litige->id}/arbitrer", [
                'sens' => 'boutique', 'resolution' => 'Tentative d’arbitrage impossible à exécuter.',
            ])->assertStatus(422);

        $this->assertSame('ouvert', $litige->fresh()->statut, 'La clôture doit être annulée avec le reste.');
        $this->assertFalse(Notification::where('telephone', self::TELEPHONE)->get()
            ->contains(fn ($n) => str_contains($n->corps_envoye, $litige->reference)));
    }

    // -----------------------------------------------------------------
    //  7. REVUE DU 24/09
    // -----------------------------------------------------------------

    public function test_confirmer_pendant_un_retour_est_refuse(): void
    {
        ['sc' => $sc] = $this->colisRemis();
        $this->creerRetour($sc, 'accepte');

        $this->post($this->urlColis($sc, 'confirmer'), ['telephone' => self::TELEPHONE])
            ->assertSessionHas('erreur');

        $this->assertNull($sc->fresh()->confirme_par_client_le);
    }

    /**
     * Une confirmation posée pendant un blocage ne libérait rien, et le
     * balayage horaire ignorait les commandes confirmées : l'argent de la
     * boutique restait bloqué pour toujours une fois le blocage levé.
     */
    public function test_une_confirmation_bloquee_est_liberee_quand_le_blocage_se_leve(): void
    {
        ['sc' => $sc] = $this->colisRemis();
        $retour = $this->creerRetour($sc, 'accepte');
        $sc->update(['confirme_par_client_le' => now()]);   // confirmation antérieure au correctif

        $sequestre = app(SequestreService::class);
        $sequestre->libererLesEchues();
        $this->assertSame('sequestre', $sc->fresh()->etat_fonds->value, 'Le retour bloque encore.');

        $retour->update(['statut' => 'clos']);
        $this->assertSame(1, $sequestre->libererLesEchues());
        $this->assertSame('reverse', $sc->fresh()->etat_fonds->value);
    }

    /**
     * La vérification se fait désormais sur la ligne VERROUILLÉE, relue en
     * base : un objet en mémoire périmé (fonds rendus entre-temps par une
     * autre requête) ne suffit plus à payer la boutique.
     */
    public function test_liberer_relit_l_etat_en_base_sous_verrou(): void
    {
        ['sc' => $sc] = $this->colisRemis();
        $sc->update(['confirme_par_client_le' => now()]);
        $perime = $sc->fresh();   // en mémoire : « sequestre », libérable

        // Une autre requête rembourse le client entre-temps.
        SousCommande::whereKey($sc->id)->update(['etat_fonds' => 'rembourse']);

        try {
            app(SequestreService::class)->liberer($perime, 'client');
            $this->fail('La libération aurait dû être refusée.');
        } catch (\RuntimeException) {
        }

        $this->assertSame('rembourse', $sc->fresh()->etat_fonds->value);
        $this->assertDatabaseMissing('evenements_commande', ['sous_commande_id' => $sc->id, 'type' => 'fonds_liberes']);
    }

    private function creerRetour(SousCommande $sc, string $statut): Retour
    {
        return Retour::create([
            'sous_commande_id' => $sc->id, 'reference' => 'RET-TEST-' . $sc->id,
            'type' => 'remboursement', 'motif' => 'ne_convient_pas', 'statut' => $statut,
        ]);
    }

    public function test_l_admin_ne_peut_pas_ouvrir_de_litige_une_fois_la_boutique_payee(): void
    {
        ['sc' => $sc, 'admin' => $admin] = $this->colisRemis();
        $this->post($this->urlColis($sc, 'confirmer'), ['telephone' => self::TELEPHONE]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/sous-commandes/{$sc->id}/litiges", [
                'motif' => 'autre', 'description' => 'Réclamation arrivée après le paiement de la boutique.',
            ])->assertStatus(422);
    }
}
