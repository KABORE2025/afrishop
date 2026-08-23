<?php

namespace Tests\Feature\Api;

use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase, CreeDonneesTrait;

    public function test_inscription_cree_un_compte_client_et_renvoie_un_jeton(): void
    {
        $pays = $this->creerPays();

        $reponse = $this->postJson('/api/auth/inscription', [
            'pays_id'      => $pays->id,
            'nom'          => 'Aminata Client',
            'telephone'    => '22670001122',
            'mot_de_passe' => 'mot-de-passe-sur',
        ]);

        $reponse->assertCreated();
        $reponse->assertJsonPath('utilisateur.role', 'client');
        $this->assertNotEmpty($reponse->json('jeton'));
        $this->assertDatabaseHas('utilisateurs', ['telephone' => '22670001122', 'role' => 'client']);
    }

    public function test_inscription_ignore_un_role_fourni_dans_la_requete(): void
    {
        $pays = $this->creerPays();

        $this->postJson('/api/auth/inscription', [
            'pays_id' => $pays->id, 'nom' => 'Tentative', 'telephone' => '22670009999',
            'mot_de_passe' => 'mot-de-passe-sur', 'role' => 'admin',
        ])->assertCreated();

        $this->assertDatabaseHas('utilisateurs', ['telephone' => '22670009999', 'role' => 'client']);
        $this->assertDatabaseMissing('utilisateurs', ['telephone' => '22670009999', 'role' => 'admin']);
    }

    public function test_connexion_avec_le_bon_mot_de_passe_renvoie_un_jeton(): void
    {
        $pays = $this->creerPays();
        $this->creerUtilisateur($pays, ['telephone' => '22670001122', 'mot_de_passe' => 'secret1234']);

        $this->postJson('/api/auth/connexion', ['telephone' => '22670001122', 'mot_de_passe' => 'secret1234'])
            ->assertOk()
            ->assertJsonStructure(['utilisateur', 'jeton']);
    }

    public function test_connexion_avec_un_mauvais_mot_de_passe_est_rejetee(): void
    {
        $pays = $this->creerPays();
        $this->creerUtilisateur($pays, ['telephone' => '22670001122', 'mot_de_passe' => 'secret1234']);

        $this->postJson('/api/auth/connexion', ['telephone' => '22670001122', 'mot_de_passe' => 'mauvais'])
            ->assertStatus(422);
    }

    public function test_moi_exige_une_authentification(): void
    {
        $this->getJson('/api/auth/moi')->assertStatus(401);
    }

    public function test_moi_renvoie_lutilisateur_connecte(): void
    {
        $pays = $this->creerPays();
        $utilisateur = $this->creerUtilisateur($pays);

        $this->actingAs($utilisateur, 'sanctum')
            ->getJson('/api/auth/moi')
            ->assertOk()
            ->assertJsonPath('id', $utilisateur->id);
    }

    public function test_deconnexion_revoque_le_jeton_courant(): void
    {
        $pays = $this->creerPays();
        $utilisateur = $this->creerUtilisateur($pays);
        $jeton = $utilisateur->createToken('api')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$jeton}")
            ->postJson('/api/auth/deconnexion')
            ->assertOk();

        $this->assertSame(0, $utilisateur->tokens()->count());
    }
}
