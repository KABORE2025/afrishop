<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

class AgentRemiseTest extends TestCase
{
    use CreeDonneesTrait, RefreshDatabase;

    public function test_rattache_un_livreur_existant_de_la_meme_ville(): void
    {
        $pays = $this->creerPays();
        $ville = $this->creerVille($pays);
        ['boutique' => $boutique, 'utilisateur' => $vendeur] = $this->creerBoutiqueAvecVendeur($pays, ['ville_id' => $ville->id]);
        $livreur = $this->creerUtilisateur($pays, ['role' => 'livreur', 'ville_id' => $ville->id]);

        $this->actingAs($vendeur, 'sanctum')
            ->postJson('/api/vendeur/agents-remise/rattachements', ['livreur_id' => $livreur->id])
            ->assertCreated();

        $this->assertDatabaseHas('agents_remise_boutiques', [
            'boutique_id' => $boutique->id, 'livreur_id' => $livreur->id, 'statut' => 'actif',
        ]);
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
