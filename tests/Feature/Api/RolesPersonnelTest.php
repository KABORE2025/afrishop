<?php

namespace Tests\Feature\Api;

use App\Models\Candidature;
use App\Models\Litige;
use App\Models\Produit;
use App\Models\SousCommande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

/**
 * Deux niveaux de personnel : l'AGENT aide (support), l'ADMIN décide de
 * l'argent et des accès. La barrière est côté serveur ; ces tests la
 * vérifient route par route.
 */
class RolesPersonnelTest extends TestCase
{
    use CreeDonneesTrait, RefreshDatabase;

    /** Commande retirée en boutique (code envoyé, colis prêt) + un agent + un admin. */
    private function contexte(): array
    {
        $pays = $this->creerPays(['ouvert_aux_boutiques' => true]);
        $ville = $this->creerVille($pays);
        $this->creerZoneLivraison($pays, $ville);
        $categorie = $this->creerCategorie();
        ['boutique' => $boutique, 'utilisateur' => $vendeur] = $this->creerBoutiqueAvecVendeur($pays, ['ville_id' => $ville->id]);
        ['variante' => $variante, 'produit' => $produit] = $this->creerProduitAvecVariante($boutique, $categorie, [], ['stock' => 10, 'prix_ttc_cfa' => 5_000]);

        $this->postJson('/api/commandes', [
            'pays_id' => $pays->id, 'articles' => [['variante_id' => $variante->id, 'quantite' => 1]],
            'nom' => 'Client Test', 'telephone' => '22670001122', 'ville_id' => $ville->id, 'quartier' => 'Z',
            'mode_livraison' => 'retrait_boutique', 'mode_paiement' => 'mobile_money',
        ])->assertCreated();
        $sc = SousCommande::firstOrFail();
        $this->actingAs($vendeur, 'sanctum')->postJson("/api/vendeur/commandes/{$sc->id}/preparer-retrait")->assertOk();

        return [
            'pays' => $pays, 'sc' => $sc->fresh(), 'produit' => $produit,
            'agent' => $this->creerUtilisateur($pays, ['role' => 'agent']),
            'admin' => $this->creerUtilisateur($pays, ['role' => 'admin']),
        ];
    }

    public function test_l_agent_fait_le_travail_du_support(): void
    {
        ['sc' => $sc, 'agent' => $agent] = $this->contexte();
        $motif = ['motif' => 'Client au téléphone, SMS jamais reçu.'];

        $this->actingAs($agent, 'sanctum')->getJson('/api/admin/tableau-de-bord')->assertOk();
        $this->actingAs($agent, 'sanctum')->getJson('/api/admin/sous-commandes?q=' . urlencode($sc->reference))->assertOk();
        $this->actingAs($agent, 'sanctum')->getJson("/api/admin/sous-commandes/{$sc->id}")
            ->assertOk()->assertJsonPath('actions.peut_cloturer_retour', false);
        $this->actingAs($agent, 'sanctum')->postJson("/api/admin/sous-commandes/{$sc->id}/code-remise/renvoyer", $motif)->assertOk();
        $this->actingAs($agent, 'sanctum')->getJson("/api/admin/sous-commandes/{$sc->id}/code-remise?" . http_build_query($motif))->assertOk();
        $this->actingAs($agent, 'sanctum')->getJson('/api/admin/candidatures')->assertOk();
        $this->actingAs($agent, 'sanctum')->getJson('/api/admin/litiges')->assertOk();
        $this->actingAs($agent, 'sanctum')->getJson('/api/admin/produits')->assertOk();
    }

    public function test_l_agent_ne_touche_ni_a_l_argent_ni_aux_acces(): void
    {
        ['pays' => $pays, 'sc' => $sc, 'produit' => $produit, 'agent' => $agent] = $this->contexte();
        $litige = Litige::create([
            'sous_commande_id' => $sc->id, 'reference' => 'LIT-TEST-1', 'motif' => 'autre',
            'description' => 'Test des droits.', 'statut' => 'ouvert',
        ]);
        $candidature = Candidature::create([
            'pays_id' => $pays->id, 'nom_boutique' => 'B', 'responsable' => 'R', 'telephone' => '22670999999',
            'description' => 'Test des droits.', 'statut' => 'en_attente',
        ]);

        $interdits = [
            ['post', "/api/admin/litiges/{$litige->id}/arbitrer", ['sens' => 'client', 'resolution' => 'Tentative par un agent.']],
            ['post', "/api/admin/sous-commandes/{$sc->id}/cloturer-retour", ['motif' => 'Tentative par un agent.']],
            ['post', "/api/admin/candidatures/{$candidature->id}/accepter", []],
            ['post', "/api/admin/candidatures/{$candidature->id}/refuser", ['motif' => 'Tentative par un agent.']],
            ['post', "/api/admin/produits/{$produit->id}/moderer", ['decision' => 'publie']],
            ['get', '/api/admin/sequestre', []],
            ['get', '/api/admin/reversements', []],
            ['get', '/api/admin/administrateurs', []],
            ['post', '/api/admin/administrateurs', ['nom' => 'X', 'telephone' => '22670888888', 'role' => 'admin']],
        ];

        foreach ($interdits as [$methode, $url, $corps]) {
            $this->actingAs($agent, 'sanctum')->json($methode, $url, $corps)
                ->assertForbidden();
        }

        $this->assertSame('ouvert', $litige->fresh()->statut);
        $this->assertSame('en_attente', $candidature->fresh()->statut);
        $this->assertDatabaseMissing('utilisateurs', ['telephone' => '22670888888']);
    }

    public function test_l_agent_peut_ouvrir_un_litige_pour_un_client(): void
    {
        ['sc' => $sc, 'agent' => $agent] = $this->contexte();
        $sc->update(['etat_fonds' => 'sequestre', 'statut' => 'livree', 'livre_le' => now()]);

        $this->actingAs($agent, 'sanctum')->postJson("/api/admin/sous-commandes/{$sc->id}/litiges", [
            'motif' => 'endommage', 'description' => 'Appel du client : produit cassé à l’ouverture.',
        ])->assertCreated();
    }

    public function test_l_admin_cree_un_agent(): void
    {
        ['admin' => $admin] = $this->contexte();

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/administrateurs', [
            'nom' => 'Agent Support', 'telephone' => '22670444444', 'role' => 'agent',
        ])->assertCreated();

        $this->assertDatabaseHas('utilisateurs', ['telephone' => '22670444444', 'role' => 'agent']);
        $this->assertDatabaseHas('journal_administration', ['agent_id' => $admin->id, 'action' => 'agent_cree']);
    }

    public function test_le_numero_du_service_client_est_affiche(): void
    {
        config()->set('afrishop.support.telephone', '+22676921202');

        $this->get('/')->assertOk()->assertSee('+226 76 92 12 02')->assertSee('tel:+22676921202', false);
        $this->get('/suivi')->assertOk()->assertSee('+226 76 92 12 02');
    }
}
