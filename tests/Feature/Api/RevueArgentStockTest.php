<?php

namespace Tests\Feature\Api;

use App\Models\Candidature;
use App\Models\SousCommande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

/**
 * Revue du 26/09 : trois anomalies reproduites avant correction.
 *   · une vente libérée après le passage hebdomadaire n'était jamais versée ;
 *   · un même article sur deux lignes faisait passer le stock en négatif ;
 *   · accepter une candidature changeait le rôle d'un compte existant.
 */
class RevueArgentStockTest extends TestCase
{
    use CreeDonneesTrait, RefreshDatabase;

    private function contexte(int $stock = 6, int $prix = 20_000): array
    {
        $pays = $this->creerPays();
        $ville = $this->creerVille($pays);
        $this->creerZoneLivraison($pays, $ville);
        $categorie = $this->creerCategorie();
        ['boutique' => $boutique, 'utilisateur' => $vendeur] = $this->creerBoutiqueAvecVendeur($pays, ['ville_id' => $ville->id]);
        ['variante' => $variante] = $this->creerProduitAvecVariante($boutique, $categorie, [], ['stock' => $stock, 'prix_ttc_cfa' => $prix]);

        return compact('pays', 'ville', 'boutique', 'vendeur', 'variante');
    }

    private function commander(array $c, array $articles)
    {
        return $this->postJson('/api/commandes', [
            'pays_id' => $c['pays']->id, 'articles' => $articles,
            'nom' => 'Client Test', 'telephone' => '22670001122',
            'ville_id' => $c['ville']->id, 'quartier' => 'Zone 1',
            'mode_livraison' => 'retrait_boutique', 'mode_paiement' => 'mobile_money',
        ]);
    }

    // -----------------------------------------------------------------
    //  STOCK
    // -----------------------------------------------------------------

    public function test_le_meme_article_sur_deux_lignes_ne_depasse_pas_le_stock(): void
    {
        $c = $this->contexte(stock: 6);
        $v = $c['variante'];

        $this->commander($c, [['variante_id' => $v->id, 'quantite' => 5], ['variante_id' => $v->id, 'quantite' => 5]])
            ->assertStatus(422);

        $this->assertSame(6, $v->fresh()->stock, 'Rien ne doit être vendu : 10 demandés pour 6 en stock.');
        $this->assertSame(0, SousCommande::count());
    }

    public function test_deux_lignes_du_meme_article_sont_regroupees(): void
    {
        $c = $this->contexte(stock: 6);
        $v = $c['variante'];

        $this->commander($c, [['variante_id' => $v->id, 'quantite' => 2], ['variante_id' => $v->id, 'quantite' => 3]])
            ->assertCreated();

        $this->assertSame(1, $v->fresh()->stock);
        $this->assertDatabaseCount('lignes_commande', 1);
        $this->assertDatabaseHas('lignes_commande', ['variante_id' => $v->id, 'quantite' => 5]);
    }

    // -----------------------------------------------------------------
    //  VERSEMENTS
    // -----------------------------------------------------------------

    public function test_une_vente_liberee_apres_le_passage_du_lundi_est_versee_la_semaine_suivante(): void
    {
        $c = $this->contexte();
        $this->commander($c, [['variante_id' => $c['variante']->id, 'quantite' => 1]])->assertCreated();
        $sc = SousCommande::firstOrFail();

        // Retirée un vendredi ; le versement est préparé le lundi à 07:00.
        $this->travelTo(now()->next('Friday')->setTime(10, 0));
        $this->actingAs($c['vendeur'], 'sanctum')->postJson("/api/vendeur/commandes/{$sc->id}/preparer-retrait")->assertOk();
        $this->actingAs($c['vendeur'], 'sanctum')->postJson("/api/vendeur/commandes/{$sc->id}/confirmer-retrait",
            ['code_retrait' => $sc->fresh()->code_retrait])->assertOk();

        // Lundi 07:00 : fenêtre de 72 h pas finie, rien à verser.
        $this->travelTo(now()->next('Monday')->setTime(7, 0));
        $this->artisan('afrishop:liberer-fonds');
        $this->artisan('afrishop:preparer-reversements');
        $this->assertSame(0, DB::table('reversement_lignes')->count());

        // Libérée lundi à 11:00, versée au passage du lundi suivant.
        $this->travelTo(now()->setTime(11, 0));
        $this->artisan('afrishop:liberer-fonds');
        $this->assertSame('reverse', $sc->fresh()->etat_fonds->value);

        $this->travelTo(now()->next('Monday')->setTime(7, 0));
        $this->artisan('afrishop:preparer-reversements');

        $this->assertSame(1, DB::table('reversement_lignes')->where('sous_commande_id', $sc->id)->count());

        // Et jamais deux fois.
        $this->travelTo(now()->next('Monday')->setTime(7, 0));
        $this->artisan('afrishop:preparer-reversements');
        $this->assertSame(1, DB::table('reversement_lignes')->where('sous_commande_id', $sc->id)->count());
    }

    // -----------------------------------------------------------------
    //  CANDIDATURES
    // -----------------------------------------------------------------

    public function test_une_candidature_ne_change_jamais_le_role_d_un_livreur(): void
    {
        $pays = $this->creerPays();
        $admin = $this->creerUtilisateur($pays, ['role' => 'admin']);
        $livreur = $this->creerUtilisateur($pays, ['role' => 'livreur', 'telephone' => '22670555555']);

        $candidature = Candidature::create([
            'pays_id' => $pays->id, 'nom_boutique' => 'Boutique piège', 'responsable' => 'Inconnu',
            'telephone' => '22670555555', 'description' => 'Candidature avec le numéro d’un livreur.',
            'statut' => 'en_attente',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/candidatures/{$candidature->id}/accepter")
            ->assertStatus(422);

        $this->assertSame('livreur', $livreur->fresh()->role);
        $this->assertSame('en_attente', $candidature->fresh()->statut);
    }

    public function test_une_candidature_d_un_simple_client_devient_vendeur(): void
    {
        $pays = $this->creerPays();
        $admin = $this->creerUtilisateur($pays, ['role' => 'admin']);
        $client = $this->creerUtilisateur($pays, ['role' => 'client', 'telephone' => '22670666666']);

        $candidature = Candidature::create([
            'pays_id' => $pays->id, 'nom_boutique' => 'Chez Awa', 'responsable' => 'Awa',
            'telephone' => '22670666666', 'description' => 'Pagnes tissés.', 'statut' => 'en_attente',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/candidatures/{$candidature->id}/accepter")
            ->assertCreated();

        $this->assertSame('vendeur', $client->fresh()->role);
    }
}
