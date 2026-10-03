<?php

namespace Tests\Feature;

use App\Models\CodeQr;
use App\Models\LotQr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

/**
 * Étiquettes QR : le système existait mais plantait au premier lot
 * (enum manquant, pays non renseigné, numéros en collision). Ces tests
 * en verrouillent le parcours complet.
 */
class EtiquettesQrTest extends TestCase
{
    use CreeDonneesTrait, RefreshDatabase;

    private function contexte(): array
    {
        $pays = $this->creerPays();
        $categorie = $this->creerCategorie();
        ['boutique' => $b, 'utilisateur' => $vendeur] = $this->creerBoutiqueAvecVendeur($pays);
        ['produit' => $karite] = $this->creerProduitAvecVariante($b, $categorie, ['nom' => 'Beurre de karité', 'tracable' => true]);
        ['produit' => $savon] = $this->creerProduitAvecVariante($b, $categorie, ['nom' => 'Savon au karité', 'tracable' => true]);
        ['produit' => $panier] = $this->creerProduitAvecVariante($b, $categorie, ['nom' => 'Panier en osier']);

        return compact('b', 'vendeur', 'karite', 'savon', 'panier') + ['admin' => $this->creerUtilisateur($pays, ['role' => 'admin'])];
    }

    private function lot(int $produitId, int $quantite = 5): array
    {
        return ['produit_id' => $produitId, 'date_fabrication' => '2026-09-01', 'date_expiration' => '2027-09-01',
            'fabricant' => 'Coopérative de Léo', 'quantite' => $quantite];
    }

    public function test_deux_produits_fabriques_le_meme_jour_n_ont_pas_les_memes_numeros(): void
    {
        ['admin' => $admin, 'karite' => $karite, 'savon' => $savon] = $this->contexte();

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/lots-qr', $this->lot($karite->id))->assertCreated()
            ->assertJsonPath('premier_code', '01/09/2026/0001');
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/lots-qr', $this->lot($savon->id))->assertCreated()
            ->assertJsonPath('premier_code', '01/09/2026/0006');

        $this->assertSame(10, CodeQr::count());
        $this->assertNotNull(LotQr::first()->pays_id);
    }

    public function test_la_boutique_demande_et_afrishop_genere(): void
    {
        ['admin' => $admin, 'vendeur' => $vendeur, 'karite' => $karite, 'panier' => $panier] = $this->contexte();

        $this->actingAs($vendeur, 'sanctum')->postJson('/api/vendeur/lots-qr/demandes', $this->lot($panier->id))
            ->assertStatus(422);   // produit non traçable

        $id = $this->actingAs($vendeur, 'sanctum')->postJson('/api/vendeur/lots-qr/demandes', $this->lot($karite->id, 3))
            ->assertCreated()->json('lot.id');
        $this->assertSame(0, CodeQr::count(), 'Une demande ne crée aucune étiquette.');

        // Le vendeur ne peut pas générer lui-même.
        $this->actingAs($vendeur, 'sanctum')->postJson("/api/admin/lots-qr/{$id}/generer")->assertForbidden();

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/lots-qr/{$id}/generer")->assertOk();
        $this->assertSame(3, CodeQr::where('lot_qr_id', $id)->count());
        $this->assertSame('genere', LotQr::find($id)->statut->value);

        $this->actingAs($vendeur, 'sanctum')->getJson('/api/vendeur/lots-qr')->assertOk()->assertJsonPath('data.0.statut', 'genere');
    }

    public function test_la_planche_ne_s_ouvre_que_par_un_lien_signe(): void
    {
        ['admin' => $admin, 'karite' => $karite] = $this->contexte();
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/lots-qr', $this->lot($karite->id, 2))->assertCreated();
        $lot = LotQr::firstOrFail();

        $this->get("/console/lots-qr/{$lot->id}/planche")->assertForbidden();

        $url = $this->actingAs($admin, 'sanctum')->getJson("/api/admin/lots-qr/{$lot->id}/lien-planche")->assertOk()->json('url');
        $this->get($url)->assertOk()->assertSee('01/09/2026/0001');
        $this->assertSame('imprime', $lot->fresh()->statut->value);

        // Un client scanne une étiquette : la page de vérification répond.
        $code = CodeQr::where('lot_qr_id', $lot->id)->firstOrFail();
        $this->get('/v/'.$code->jeton)->assertOk()->assertSee('Beurre de karité');
        $this->assertSame(1, $code->fresh()->nb_scans);
    }

    public function test_refuser_une_demande_et_rappeler_un_lot(): void
    {
        ['admin' => $admin, 'vendeur' => $vendeur, 'karite' => $karite] = $this->contexte();
        $id = $this->actingAs($vendeur, 'sanctum')->postJson('/api/vendeur/lots-qr/demandes', $this->lot($karite->id))->json('lot.id');

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/lots-qr/{$id}/refuser", ['motif' => 'Fabricant non vérifié par Afrishop.'])->assertOk();
        $this->assertSame('refuse', LotQr::find($id)->statut->value);

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/lots-qr', $this->lot($karite->id, 2))->assertCreated();
        $lot = LotQr::where('statut', 'genere')->firstOrFail();
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/lots-qr/{$lot->id}/rappel", ['motif' => 'Contrefaçon signalée sur ce lot.'])->assertOk();
        $this->assertSame(2, CodeQr::where('lot_qr_id', $lot->id)->where('statut', 'rappele')->count());

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/lots-qr')->assertOk()->assertJsonCount(2, 'data');
    }
}
