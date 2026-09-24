<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Notification;
use App\Models\SousCommande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

/**
 * Suivi sans compte : « Suivre ma commande », renvoi du code par le
 * client, reçu PDF. Chaque geste exige la preuve du téléphone, retenue
 * ensuite en session.
 */
class SuiviCommandeTest extends TestCase
{
    use CreeDonneesTrait, RefreshDatabase;

    private const TELEPHONE = '22670001122';

    /** Commande payée, en retrait boutique, code de retrait envoyé. */
    private function commandePrete(): array
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
            'nom' => 'Awa Ouédraogo', 'telephone' => self::TELEPHONE,
            'ville_id' => $ville->id, 'quartier' => 'Zone 1',
            'mode_livraison' => 'retrait_boutique',
            'mode_paiement' => 'mobile_money',
        ])->assertCreated();

        $sc = SousCommande::where('boutique_id', $boutique->id)->firstOrFail();
        $this->actingAs($vendeur, 'sanctum')->postJson("/api/vendeur/commandes/{$sc->id}/preparer-retrait")->assertOk();

        return ['commande' => Commande::firstOrFail(), 'sc' => $sc->fresh()];
    }

    private function smsDuCode(SousCommande $sc)
    {
        return Notification::where('telephone', self::TELEPHONE)->get()
            ->filter(fn ($n) => str_contains($n->corps_envoye, $sc->code_retrait));
    }

    // -----------------------------------------------------------------
    //  SUIVRE MA COMMANDE
    // -----------------------------------------------------------------

    public function test_le_lien_de_suivi_est_dans_l_en_tete_et_la_page_s_affiche(): void
    {
        $this->get('/')->assertOk()->assertSee('Suivre ma commande');
        $this->get('/suivi')->assertOk()->assertSee('Numéro de commande');
    }

    public function test_reference_et_telephone_menent_a_la_commande(): void
    {
        ['commande' => $commande] = $this->commandePrete();

        // Référence en minuscules, téléphone sans indicatif : accepté.
        $this->post('/suivi', ['reference' => strtolower($commande->reference), 'telephone' => '70 00 11 22'])
            ->assertRedirect("/commande/{$commande->reference}");

        // Le téléphone n'est plus redemandé sur la page.
        $this->get("/commande/{$commande->reference}")
            ->assertOk()
            ->assertSee('Télécharger le reçu (PDF)')
            ->assertDontSee('Téléphone utilisé pour la commande');
    }

    public function test_un_mauvais_telephone_ne_donne_rien(): void
    {
        ['commande' => $commande] = $this->commandePrete();

        $this->from('/suivi')->post('/suivi', ['reference' => $commande->reference, 'telephone' => '70999999'])
            ->assertRedirect('/suivi')->assertSessionHas('erreur');

        $this->assertEmpty(session('commandes_verifiees', []));
    }

    // -----------------------------------------------------------------
    //  RENVOI DU CODE
    // -----------------------------------------------------------------

    public function test_le_client_fait_renvoyer_le_meme_code_au_numero_de_la_commande(): void
    {
        ['commande' => $commande, 'sc' => $sc] = $this->commandePrete();
        $this->assertCount(1, $this->smsDuCode($sc));

        $this->get("/commande/{$commande->reference}")->assertSee('pas reçu mon code');

        $this->post("/commande/{$commande->reference}/colis/{$sc->reference}/renvoyer-code", ['telephone' => '70001122'])
            ->assertRedirect()->assertSessionHas('succes');

        $this->assertCount(2, $this->smsDuCode($sc));
        $this->assertSame($sc->code_retrait, $sc->fresh()->code_retrait, 'Jamais un nouveau code.');
        $this->assertDatabaseHas('evenements_commande', [
            'sous_commande_id' => $sc->id, 'type' => 'code_renvoye', 'auteur_type' => 'client',
        ]);

        // Le SMS mène à la page de commande.
        $this->assertStringContainsString(
            route('commande.confirmee', $commande->reference),
            $this->smsDuCode($sc)->last()->corps_envoye
        );
    }

    public function test_trois_renvois_par_jour_au_plus(): void
    {
        ['commande' => $commande, 'sc' => $sc] = $this->commandePrete();
        $url = "/commande/{$commande->reference}/colis/{$sc->reference}/renvoyer-code";

        $this->post('/suivi', ['reference' => $commande->reference, 'telephone' => self::TELEPHONE]);

        for ($i = 0; $i < 3; $i++) {
            $this->post($url)->assertSessionHas('succes');
        }
        $this->post($url)->assertSessionHas('erreur');

        $this->assertCount(4, $this->smsDuCode($sc), '1 envoi initial + 3 renvois, pas un de plus.');
    }

    public function test_sans_le_bon_telephone_aucun_renvoi(): void
    {
        ['commande' => $commande, 'sc' => $sc] = $this->commandePrete();

        $this->post("/commande/{$commande->reference}/colis/{$sc->reference}/renvoyer-code", ['telephone' => '70999999'])
            ->assertSessionHas('erreur');

        $this->assertCount(1, $this->smsDuCode($sc));
    }

    // -----------------------------------------------------------------
    //  REÇU PDF
    // -----------------------------------------------------------------

    public function test_le_recu_pdf_est_telecharge_apres_paiement(): void
    {
        ['commande' => $commande] = $this->commandePrete();

        $reponse = $this->post("/commande/{$commande->reference}/recu", ['telephone' => self::TELEPHONE]);

        $reponse->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $reponse->getContent());
        $this->assertStringContainsString("recu-{$commande->reference}.pdf", $reponse->headers->get('content-disposition'));
    }

    public function test_pas_de_recu_sans_le_bon_telephone(): void
    {
        ['commande' => $commande] = $this->commandePrete();

        $this->get("/commande/{$commande->reference}/recu")->assertRedirect()->assertSessionHas('erreur');
    }

    public function test_pas_de_recu_pour_un_paiement_non_encaisse(): void
    {
        ['commande' => $commande] = $this->commandePrete();
        $commande->update(['statut_paiement' => 'attente']);

        $this->post("/commande/{$commande->reference}/recu", ['telephone' => self::TELEPHONE])
            ->assertRedirect()->assertSessionHas('erreur');
    }
}
