<?php

namespace Tests\Feature\Api;

use App\Models\Commande;
use App\Models\SousCommande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

/**
 * Revue du 22/09 : fuite du code de retrait au suivi, références
 * devinables, absence de limite de tentatives, codes en clair en base.
 */
class SecuriteTest extends TestCase
{
    use CreeDonneesTrait, RefreshDatabase;

    private function commandeRetraitPreparee(): array
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

        $sc = SousCommande::firstOrFail();
        $this->actingAs($vendeur, 'sanctum')
            ->postJson("/api/vendeur/commandes/{$sc->id}/preparer-retrait")->assertOk();

        return ['pays' => $pays, 'sc' => $sc->fresh(), 'commande' => Commande::firstOrFail()];
    }

    public function test_le_suivi_ne_revele_jamais_le_code_de_retrait(): void
    {
        ['sc' => $sc, 'commande' => $commande] = $this->commandeRetraitPreparee();
        $code = $sc->code_retrait;

        $reponse = $this->getJson('/api/commandes/suivi?' . http_build_query([
            'reference' => $commande->reference, 'telephone' => '+226 70 00 11 22',
        ]));

        $reponse->assertOk()->assertJsonPath('colis.0.statut', 'prete');
        $this->assertStringNotContainsString('code_retrait', $reponse->getContent());
        $this->assertStringNotContainsString($code, $reponse->getContent());
    }

    public function test_les_references_ne_se_suivent_pas(): void
    {
        ['commande' => $commande] = $this->commandeRetraitPreparee();

        $this->assertMatchesRegularExpression('/^BF-CMD-\d{4}-[2-9A-HJKMNP-Z]{6}$/', $commande->reference);
    }

    public function test_les_codes_et_les_sms_sont_chiffres_en_base(): void
    {
        ['sc' => $sc] = $this->commandeRetraitPreparee();

        $brut = DB::table('sous_commandes')->where('id', $sc->id)->value('code_retrait');
        $this->assertNotSame($sc->code_retrait, $brut);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $sc->code_retrait);

        foreach (DB::table('notifications')->pluck('corps_envoye') as $corps) {
            $this->assertStringNotContainsString($sc->code_retrait, $corps);
        }
    }

    public function test_la_connexion_est_limitee(): void
    {
        $pays = $this->creerPays();
        $this->creerUtilisateur($pays, ['telephone' => '22670005555']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/connexion', ['telephone' => '22670005555', 'mot_de_passe' => 'mauvais-'.$i])
                ->assertStatus(422);
        }

        $this->postJson('/api/auth/connexion', ['telephone' => '22670005555', 'mot_de_passe' => 'mauvais'])
            ->assertStatus(429);
    }

    public function test_lactivation_dagent_est_limitee_par_cnib(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->postJson('/api/agents-remise/activer', [
                    'cnib' => 'B1234567', 'telephone' => '22670001111', 'code' => sprintf('%06d', $i),
                    'mot_de_passe' => 'motdepasse1', 'mot_de_passe_confirmation' => 'motdepasse1',
                ])->assertStatus(422);
        }

        // Nouvelle IP, même CNIB : toujours bloqué.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->postJson('/api/agents-remise/activer', [
                'cnib' => 'b-123 4567', 'telephone' => '22670001111', 'code' => '999999',
                'mot_de_passe' => 'motdepasse1', 'mot_de_passe_confirmation' => 'motdepasse1',
            ])->assertStatus(429);
    }

    public function test_cloture_de_retour_par_ladmin_exige_un_motif(): void
    {
        ['pays' => $pays, 'sc' => $sc] = $this->commandeRetraitPreparee();
        $admin = $this->creerUtilisateur($pays, ['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/sous-commandes/{$sc->id}/cloturer-retour", [])
            ->assertStatus(422)->assertJsonValidationErrors('motif');

        // Pas de retour en cours sur un retrait boutique.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/sous-commandes/{$sc->id}/cloturer-retour", ['motif' => 'Constat du support par téléphone.'])
            ->assertStatus(422);
    }
}
