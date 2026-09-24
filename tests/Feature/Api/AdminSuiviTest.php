<?php

namespace Tests\Feature\Api;

use App\Models\Litige;
use App\Models\SousCommande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

/**
 * Console — suivi des commandes et du séquestre, en LECTURE SEULE.
 * Deux choses sont verrouillées ici : la valeur d'un code de remise ne
 * sort jamais de ces routes, et l'admin voit POURQUOI des fonds sont
 * retenus sans disposer d'aucun moyen de les libérer.
 */
class AdminSuiviTest extends TestCase
{
    use CreeDonneesTrait, RefreshDatabase;

    private function creerContexteRetrait(): array
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
            'nom' => 'Client Test', 'telephone' => '22670001122',
            'ville_id' => $ville->id, 'quartier' => 'Zone 1',
            'mode_livraison' => 'retrait_boutique',
            'mode_paiement' => 'mobile_money',
        ])->assertCreated();

        $sc = SousCommande::where('boutique_id', $boutique->id)->firstOrFail();
        $this->actingAs($vendeur, 'sanctum')->postJson("/api/vendeur/commandes/{$sc->id}/preparer-retrait");

        $admin = $this->creerUtilisateur($pays, ['role' => 'admin']);

        return ['admin' => $admin, 'vendeur' => $vendeur, 'sc' => $sc->fresh()];
    }

    public function test_recherche_par_reference_et_par_telephone(): void
    {
        ['admin' => $admin, 'sc' => $sc] = $this->creerContexteRetrait();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/sous-commandes?q=' . urlencode($sc->reference))
            ->assertOk()->assertJsonPath('data.0.id', $sc->id);

        // Sans l'indicatif pays : c'est ainsi que le client le donne.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/sous-commandes?q=70001122')
            ->assertOk()->assertJsonPath('data.0.id', $sc->id);
    }

    public function test_la_fiche_suit_le_code_sans_jamais_le_montrer(): void
    {
        ['admin' => $admin, 'sc' => $sc] = $this->creerContexteRetrait();
        $code = $sc->code_retrait;
        $this->assertNotNull($code);

        $reponse = $this->actingAs($admin, 'sanctum')->getJson("/api/admin/sous-commandes/{$sc->id}");

        $reponse->assertOk()
            ->assertJsonPath('code.type', 'retrait')
            ->assertJsonPath('code.genere', true)
            ->assertJsonPath('code.valide', false)
            ->assertJsonPath('code.sms.0.statut', 'en_file')
            ->assertJsonPath('actions.peut_renvoyer_code', true);

        $this->assertStringNotContainsString($code, $reponse->getContent());
    }

    public function test_la_fiche_ne_journalise_pas_d_acces_au_code(): void
    {
        ['admin' => $admin, 'sc' => $sc] = $this->creerContexteRetrait();

        $this->actingAs($admin, 'sanctum')->getJson("/api/admin/sous-commandes/{$sc->id}")->assertOk();

        $this->assertDatabaseMissing('journal_administration', ['cible_id' => $sc->id]);
    }

    public function test_sequestre_explique_pourquoi_les_fonds_sont_geles(): void
    {
        ['admin' => $admin, 'sc' => $sc] = $this->creerContexteRetrait();
        $sc->update(['etat_fonds' => 'sequestre', 'statut' => 'livree', 'livre_le' => now()]);
        Litige::create([
            'sous_commande_id' => $sc->id, 'reference' => 'LIT-TEST-1',
            'motif' => 'non_conforme', 'description' => 'Pas la bonne taille.', 'statut' => 'ouvert',
        ]);

        $reponse = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/sequestre?etape=bloques');

        $reponse->assertOk()
            ->assertJsonPath('totaux.bloques.nombre', 1)
            ->assertJsonPath('totaux.delai.nombre', 0)
            ->assertJsonPath('data.0.id', $sc->id)
            ->assertJsonPath('data.0.fonds.liberable', false)
            ->assertJsonPath('data.0.fonds.raison', 'un litige est en cours d\'examen')
            ->assertJsonPath('data.0.fonds.liberation_prevue_le', null);
    }

    public function test_sequestre_annonce_la_liberation_d_une_livraison_en_delai(): void
    {
        ['admin' => $admin, 'sc' => $sc] = $this->creerContexteRetrait();
        $sc->update(['etat_fonds' => 'sequestre', 'statut' => 'livree', 'livre_le' => now()->subDay()]);

        $reponse = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/sequestre?etape=delai');

        $reponse->assertOk()
            ->assertJsonPath('data.0.id', $sc->id)
            ->assertJsonPath('data.0.fonds.liberable', false);
        $this->assertNotNull($reponse->json('data.0.fonds.liberation_prevue_le'));
    }

    public function test_aucune_route_ne_permet_de_liberer_les_fonds_a_la_main(): void
    {
        ['admin' => $admin, 'sc' => $sc] = $this->creerContexteRetrait();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/sous-commandes/{$sc->id}/liberer")
            ->assertNotFound();
    }

    public function test_un_vendeur_n_a_pas_acces_au_suivi(): void
    {
        ['vendeur' => $vendeur, 'sc' => $sc] = $this->creerContexteRetrait();

        $this->actingAs($vendeur, 'sanctum')->getJson('/api/admin/sous-commandes?q=BF-')->assertStatus(403);
        $this->actingAs($vendeur, 'sanctum')->getJson("/api/admin/sous-commandes/{$sc->id}")->assertStatus(403);
        $this->actingAs($vendeur, 'sanctum')->getJson('/api/admin/sequestre')->assertStatus(403);
    }
}
