<?php

namespace Tests\Feature;

use App\Models\Candidature;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

/**
 * « Créer un administrateur » (console) et « Ouvrir ma boutique » (site).
 */
class BoutonsAdminEtCandidatureTest extends TestCase
{
    use CreeDonneesTrait, RefreshDatabase;

    // -----------------------------------------------------------------
    //  ADMINISTRATEURS
    // -----------------------------------------------------------------

    public function test_un_admin_cree_un_autre_admin_avec_mot_de_passe_temporaire(): void
    {
        $pays = $this->creerPays();
        $admin = $this->creerUtilisateur($pays, ['role' => 'admin']);

        $reponse = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/administrateurs', [
            'nom' => 'Fatou Sawadogo', 'telephone' => '+226 70 12 34 56',
        ])->assertCreated();

        $nouveau = Utilisateur::where('telephone', '22670123456')->firstOrFail();
        $this->assertSame('admin', $nouveau->role);
        $this->assertTrue(Hash::check($reponse->json('mot_de_passe_temporaire'), $nouveau->getAuthPassword()));

        $this->assertDatabaseHas('journal_administration', [
            'agent_id' => $admin->id, 'action' => 'administrateur_cree',
            'cible_type' => 'utilisateur', 'cible_id' => $nouveau->id,
        ]);

        // Le nouvel admin a les mêmes droits : il peut à son tour en créer un.
        $this->actingAs($nouveau, 'sanctum')->postJson('/api/admin/administrateurs', [
            'nom' => 'Troisième Admin', 'telephone' => '22670999888',
        ])->assertCreated();

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/administrateurs')
            ->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_un_compte_existant_n_est_jamais_promu_admin(): void
    {
        $pays = $this->creerPays();
        $admin = $this->creerUtilisateur($pays, ['role' => 'admin']);
        $client = $this->creerUtilisateur($pays, ['role' => 'client', 'telephone' => '22670777777']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/administrateurs', [
            'nom' => 'Tentative', 'telephone' => '22670777777',
        ])->assertStatus(422);

        $this->assertSame('client', $client->fresh()->role);
    }

    public function test_un_vendeur_ne_peut_pas_creer_d_admin(): void
    {
        $pays = $this->creerPays();
        ['utilisateur' => $vendeur] = $this->creerBoutiqueAvecVendeur($pays);

        $this->actingAs($vendeur, 'sanctum')->postJson('/api/admin/administrateurs', [
            'nom' => 'Pirate', 'telephone' => '22670111222',
        ])->assertForbidden();

        $this->assertDatabaseMissing('utilisateurs', ['telephone' => '22670111222']);
    }

    // -----------------------------------------------------------------
    //  OUVRIR MA BOUTIQUE
    // -----------------------------------------------------------------

    public function test_le_formulaire_est_accessible_depuis_l_en_tete(): void
    {
        $pays = $this->creerPays(['ouvert_aux_boutiques' => true]);
        $this->creerVille($pays);

        $this->get('/')->assertOk()->assertSee('Ouvrir ma boutique');
        $this->get('/ouvrir-ma-boutique')->assertOk()->assertSee('Nom de la boutique');
    }

    public function test_une_candidature_est_enregistree_pour_la_console(): void
    {
        $pays = $this->creerPays(['ouvert_aux_boutiques' => true]);
        $ville = $this->creerVille($pays);

        $this->post('/ouvrir-ma-boutique', [
            'nom_boutique' => 'Kôrô Pagnes', 'responsable' => 'Awa Ouédraogo',
            'telephone' => '70 00 11 22', 'ville_id' => $ville->id,
            'description' => 'Pagnes Faso Dan Fani tissés main, boutique au marché de Rood Woko depuis 2015.',
        ])->assertRedirect('/ouvrir-ma-boutique')->assertSessionHas('succes');

        $this->assertDatabaseHas('candidatures', [
            'nom_boutique' => 'Kôrô Pagnes', 'telephone' => '70001122',
            'pays_id' => $pays->id, 'ville_id' => $ville->id, 'statut' => 'en_attente',
        ]);

        // Elle apparaît dans la console.
        $admin = $this->creerUtilisateur($pays, ['role' => 'admin']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/candidatures')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_une_seule_demande_en_cours_par_numero(): void
    {
        $pays = $this->creerPays(['ouvert_aux_boutiques' => true]);
        $ville = $this->creerVille($pays);
        $demande = [
            'nom_boutique' => 'Kôrô Pagnes', 'responsable' => 'Awa', 'telephone' => '70001122',
            'ville_id' => $ville->id, 'description' => str_repeat('Pagnes tissés main. ', 3),
        ];

        $this->post('/ouvrir-ma-boutique', $demande)->assertSessionHas('succes');
        $this->post('/ouvrir-ma-boutique', $demande)->assertSessionHas('erreur');

        $this->assertSame(1, Candidature::count());
    }

    public function test_un_pays_ferme_aux_boutiques_n_est_pas_propose(): void
    {
        $france = $this->creerPays(['code_iso2' => 'FR', 'nom' => 'France', 'ouvert_a_la_vente' => true, 'ouvert_aux_boutiques' => false]);
        $paris = $this->creerVille($france, ['nom' => 'Paris', 'slug' => 'paris']);

        $this->get('/ouvrir-ma-boutique')->assertOk()->assertDontSee('Paris');
        $this->post('/ouvrir-ma-boutique', [
            'nom_boutique' => 'Boutique Paris', 'responsable' => 'X', 'telephone' => '33612345678',
            'ville_id' => $paris->id, 'description' => str_repeat('Vente de pagnes en France. ', 2),
        ])->assertSessionHasErrors('ville_id');

        $this->assertSame(0, Candidature::count());
    }
}
