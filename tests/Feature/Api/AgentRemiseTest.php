<?php

namespace Tests\Feature\Api;

use App\Models\Notification;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

class AgentRemiseTest extends TestCase
{
    use CreeDonneesTrait, RefreshDatabase;

    /**
     * Revue du 24/09 : un livreur existant n'est plus rattaché d'office.
     * Il reçoit une invitation, et rien n'est actif tant qu'il n'a pas accepté.
     */
    public function test_un_livreur_existant_doit_accepter_l_invitation(): void
    {
        $pays = $this->creerPays();
        $ville = $this->creerVille($pays);
        ['boutique' => $boutique, 'utilisateur' => $vendeur] = $this->creerBoutiqueAvecVendeur($pays, ['ville_id' => $ville->id]);
        $livreur = $this->creerUtilisateur($pays, ['role' => 'livreur', 'ville_id' => $ville->id]);

        $this->actingAs($vendeur, 'sanctum')
            ->postJson('/api/vendeur/agents-remise/rattachements', ['livreur_id' => $livreur->id])
            ->assertStatus(202);

        $this->assertDatabaseHas('agents_remise_boutiques', [
            'boutique_id' => $boutique->id, 'livreur_id' => $livreur->id, 'statut' => 'en_attente',
        ]);
        $this->assertDatabaseHas('notifications', ['destinataire_id' => $livreur->id, 'canal' => 'sms']);

        // Pas encore accepté : la boutique ne peut pas le choisir.
        $this->actingAs($vendeur, 'sanctum')->getJson('/api/vendeur/livreurs')
            ->assertOk()->assertJsonCount(0, 'data');

        // Le livreur voit l'invitation et son identifiant, puis accepte.
        $this->actingAs($livreur, 'sanctum')->getJson('/api/livreur/invitations')
            ->assertOk()
            ->assertJsonPath('mon_identifiant', $livreur->id)
            ->assertJsonPath('data.0.boutique_id', $boutique->id);

        $this->actingAs($livreur, 'sanctum')
            ->postJson("/api/livreur/invitations/{$boutique->id}", ['reponse' => 'accepter'])
            ->assertOk();

        $this->assertDatabaseHas('agents_remise_boutiques', [
            'boutique_id' => $boutique->id, 'livreur_id' => $livreur->id, 'statut' => 'actif',
        ]);
        $this->actingAs($vendeur, 'sanctum')->getJson('/api/vendeur/livreurs')
            ->assertOk()->assertJsonPath('data.0.id', $livreur->id);
    }

    public function test_un_livreur_peut_refuser(): void
    {
        $pays = $this->creerPays();
        $ville = $this->creerVille($pays);
        ['boutique' => $boutique, 'utilisateur' => $vendeur] = $this->creerBoutiqueAvecVendeur($pays, ['ville_id' => $ville->id]);
        $livreur = $this->creerUtilisateur($pays, ['role' => 'livreur', 'ville_id' => $ville->id]);

        $this->actingAs($vendeur, 'sanctum')
            ->postJson('/api/vendeur/agents-remise/rattachements', ['livreur_id' => $livreur->id]);
        $this->actingAs($livreur, 'sanctum')
            ->postJson("/api/livreur/invitations/{$boutique->id}", ['reponse' => 'refuser'])
            ->assertOk();

        $this->assertDatabaseHas('agents_remise_boutiques', [
            'boutique_id' => $boutique->id, 'livreur_id' => $livreur->id, 'statut' => 'refuse',
        ]);
    }

    /** Même message pour « inconnu », « pas livreur », « autre ville » : on ne devine plus qui est livreur. */
    public function test_le_message_ne_revele_pas_qui_est_livreur(): void
    {
        $pays = $this->creerPays();
        $ville = $this->creerVille($pays);
        ['utilisateur' => $vendeur] = $this->creerBoutiqueAvecVendeur($pays, ['ville_id' => $ville->id]);
        $client = $this->creerUtilisateur($pays, ['role' => 'client', 'ville_id' => $ville->id]);

        $inconnu = $this->actingAs($vendeur, 'sanctum')
            ->postJson('/api/vendeur/agents-remise/rattachements', ['livreur_id' => 999999])->assertStatus(422)->json('message');
        $pasLivreur = $this->actingAs($vendeur, 'sanctum')
            ->postJson('/api/vendeur/agents-remise/rattachements', ['livreur_id' => $client->id])->assertStatus(422)->json('message');

        $this->assertSame($inconnu, $pasLivreur);
    }

    public function test_refuse_un_livreur_d_une_autre_ville(): void
    {
        $pays = $this->creerPays();
        $villeBoutique = $this->creerVille($pays);
        $autreVille = $this->creerVille($pays, ['nom' => 'Bobo-Dioulasso', 'slug' => 'bobo-dioulasso']);
        ['boutique' => $boutique, 'utilisateur' => $vendeur] = $this->creerBoutiqueAvecVendeur($pays, ['ville_id' => $villeBoutique->id]);
        $livreur = $this->creerUtilisateur($pays, ['role' => 'livreur', 'ville_id' => $autreVille->id]);

        $this->actingAs($vendeur, 'sanctum')
            ->postJson('/api/vendeur/agents-remise/rattachements', ['livreur_id' => $livreur->id])
            ->assertStatus(422);

        $this->assertDatabaseMissing('agents_remise_boutiques', [
            'boutique_id' => $boutique->id, 'livreur_id' => $livreur->id,
        ]);
    }

    /**
     * Revue du 27/09 : inviter par son identifiant un livreur qui n'a pas
     * encore activé son compte effaçait son code d'activation. Il ne
     * pouvait plus ni s'activer ni se connecter : compte bloqué.
     */
    public function test_un_livreur_non_active_ne_peut_pas_etre_invite_et_garde_son_code(): void
    {
        $pays = $this->creerPays();
        $ville = $this->creerVille($pays);
        ['boutique' => $boutique, 'utilisateur' => $vendeur] = $this->creerBoutiqueAvecVendeur($pays, ['ville_id' => $ville->id]);

        $this->actingAs($vendeur, 'sanctum')->postJson('/api/vendeur/agents-remise/invitations', [
            'nom' => 'Livreur Neuf', 'telephone' => '22670123456', 'cnib' => 'B1234567',
        ])->assertCreated();
        $livreur = Utilisateur::where('telephone', '22670123456')->firstOrFail();
        preg_match('/\b(\d{6})\b/', Notification::where('telephone', '22670123456')->latest('id')->first()->corps_envoye, $m);

        $this->actingAs($vendeur, 'sanctum')
            ->postJson('/api/vendeur/agents-remise/rattachements', ['livreur_id' => $livreur->id])
            ->assertStatus(422);

        // Le code reçu par SMS fonctionne toujours.
        $this->postJson('/api/agents-remise/activer', [
            'cnib' => 'B1234567', 'telephone' => '22670123456', 'code' => $m[1],
            'mot_de_passe' => 'motdepasse123', 'mot_de_passe_confirmation' => 'motdepasse123',
        ])->assertOk();

        $this->assertDatabaseHas('agents_remise_boutiques', [
            'boutique_id' => $boutique->id, 'livreur_id' => $livreur->id, 'statut' => 'actif',
        ]);
    }

    public function test_cree_un_nouveau_livreur_dans_la_ville_de_la_boutique(): void
    {
        $pays = $this->creerPays();
        $ville = $this->creerVille($pays);
        ['boutique' => $boutique, 'utilisateur' => $vendeur] = $this->creerBoutiqueAvecVendeur($pays, ['ville_id' => $ville->id]);

        $this->actingAs($vendeur, 'sanctum')->postJson('/api/vendeur/agents-remise/invitations', [
            'nom' => 'Livreuse Test', 'telephone' => '22670123456', 'cnib' => 'B1234567',
        ])->assertCreated();

        $this->assertDatabaseHas('utilisateurs', [
            'nom' => 'Livreuse Test', 'role' => 'livreur', 'ville_id' => $ville->id,
        ]);
        $this->assertDatabaseHas('agents_remise_boutiques', [
            'boutique_id' => $boutique->id, 'statut' => 'en_attente',
        ]);
    }
}
