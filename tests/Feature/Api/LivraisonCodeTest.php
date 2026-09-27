<?php

namespace Tests\Feature\Api;

use App\Models\AgentRemiseBoutique;
use App\Models\SousCommande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

/**
 * Le code de livraison ne doit pas pouvoir être deviné : 5 essais au plus
 * par colis (compteur verrouillé), 10 saisies par minute au plus par livreur.
 */
class LivraisonCodeTest extends TestCase
{
    use CreeDonneesTrait, RefreshDatabase;

    private function colisExpedie(): array
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
            'mode_paiement' => 'mobile_money',
        ])->assertCreated();

        $sc = SousCommande::where('boutique_id', $boutique->id)->firstOrFail();
        $livreur = $this->creerUtilisateur($pays, ['role' => 'livreur', 'ville_id' => $ville->id]);
        AgentRemiseBoutique::create([
            'boutique_id' => $boutique->id, 'livreur_id' => $livreur->id,
            'statut' => 'actif', 'autorise_livraison' => true,
            'invite_par_utilisateur_id' => $vendeur->id, 'accepte_le' => now(),
        ]);
        $this->actingAs($vendeur, 'sanctum')
            ->postJson("/api/vendeur/commandes/{$sc->id}/expedier", ['livreur_id' => $livreur->id])->assertOk();

        return ['sc' => $sc->fresh('expedition'), 'livreur' => $livreur];
    }

    private function mauvaisCode(SousCommande $sc): string
    {
        return $sc->expedition->code_livraison === '111111' ? '222222' : '111111';
    }

    public function test_apres_5_codes_faux_meme_le_bon_code_est_refuse(): void
    {
        ['sc' => $sc, 'livreur' => $livreur] = $this->colisExpedie();
        $url = "/api/livreur/commandes/{$sc->id}/livrer";

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($livreur, 'sanctum')->postJson($url, ['code_livraison' => $this->mauvaisCode($sc)])
                ->assertStatus(422);
        }

        $this->actingAs($livreur, 'sanctum')->postJson($url, ['code_livraison' => $sc->expedition->code_livraison])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Code bloqué après 5 essais. Appelez le service client Afrishop au '.telephone_support().'.']);

        $this->assertSame('expediee', $sc->fresh()->statut);
        $this->assertSame(5, $sc->expedition->fresh()->tentatives);
    }

    public function test_plus_de_10_saisies_par_minute_sont_refusees(): void
    {
        ['sc' => $sc, 'livreur' => $livreur] = $this->colisExpedie();
        $url = "/api/livreur/commandes/{$sc->id}/livrer";

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($livreur, 'sanctum')->postJson($url, ['code_livraison' => 'abc']);   // invalide, mais compté
        }

        $this->actingAs($livreur, 'sanctum')->postJson($url, ['code_livraison' => $sc->expedition->code_livraison])
            ->assertStatus(429);
    }
}
