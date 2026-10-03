<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\OperateurPaiement;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

/**
 * Mot de passe (changer, retrouver par SMS), activation du livreur,
 * fiche boutique et compte de paiement, gestion des boutiques.
 */
class ComptesEtBoutiquesTest extends TestCase
{
    use CreeDonneesTrait, RefreshDatabase;

    // -----------------------------------------------------------------
    //  MOT DE PASSE
    // -----------------------------------------------------------------

    public function test_changer_son_mot_de_passe_ferme_les_autres_sessions(): void
    {
        $pays = $this->creerPays();
        $u = $this->creerUtilisateur($pays, ['role' => 'vendeur', 'mot_de_passe' => 'ancienmdp1']);
        $autre = $u->createToken('autre-telephone')->plainTextToken;
        $courant = $u->createToken('ici')->plainTextToken;

        $this->withToken($courant)->postJson('/api/auth/mot-de-passe', [
            'mot_de_passe_actuel' => 'faux', 'mot_de_passe' => 'nouveaumdp1', 'mot_de_passe_confirmation' => 'nouveaumdp1',
        ])->assertStatus(422);

        $this->withToken($courant)->postJson('/api/auth/mot-de-passe', [
            'mot_de_passe_actuel' => 'ancienmdp1', 'mot_de_passe' => 'nouveaumdp1', 'mot_de_passe_confirmation' => 'nouveaumdp1',
        ])->assertOk();

        $this->assertTrue(Hash::check('nouveaumdp1', $u->fresh()->getAuthPassword()));
        $this->assertSame(1, $u->tokens()->count(), 'Seule la session courante reste ouverte.');
        $this->assertSame('ici', $u->tokens()->first()->name);
        $this->assertNotNull($autre);
    }

    public function test_mot_de_passe_oublie_par_code_sms(): void
    {
        $pays = $this->creerPays();
        $u = $this->creerUtilisateur($pays, ['role' => 'vendeur', 'telephone' => '22670112233', 'mot_de_passe' => 'oubliemdp1']);
        $u->createToken('vieille-session');

        // Même réponse pour un numéro inconnu : on ne révèle rien.
        $inconnu = $this->postJson('/api/auth/mot-de-passe-oublie', ['telephone' => '70999999'])->assertOk()->json('message');
        $connu = $this->postJson('/api/auth/mot-de-passe-oublie', ['telephone' => '70 11 22 33'])->assertOk()->json('message');
        $this->assertSame($inconnu, $connu);

        preg_match('/\b(\d{6})\b/', Notification::where('telephone', '22670112233')->latest('id')->first()->corps_envoye, $m);

        $this->postJson('/api/auth/reinitialiser', [
            'telephone' => '70112233', 'code' => $m[1], 'mot_de_passe' => 'nouveaumdp2', 'mot_de_passe_confirmation' => 'nouveaumdp2',
        ])->assertOk();

        $this->assertTrue(Hash::check('nouveaumdp2', $u->fresh()->getAuthPassword()));
        $this->assertSame(0, $u->tokens()->count(), 'Toutes les sessions sont fermées.');

        // Le code ne sert qu'une fois.
        $this->postJson('/api/auth/reinitialiser', [
            'telephone' => '70112233', 'code' => $m[1], 'mot_de_passe' => 'autremdp33', 'mot_de_passe_confirmation' => 'autremdp33',
        ])->assertStatus(422);
    }

    public function test_cinq_codes_faux_detruisent_le_code(): void
    {
        // La limite de requêtes (5/min) protège déjà ; on la retire ici pour
        // vérifier le second verrou : la destruction du code.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $pays = $this->creerPays();
        $this->creerUtilisateur($pays, ['role' => 'vendeur', 'telephone' => '22670445566', 'mot_de_passe' => 'oubliemdp1']);
        $this->postJson('/api/auth/mot-de-passe-oublie', ['telephone' => '22670445566']);
        preg_match('/\b(\d{6})\b/', Notification::latest('id')->first()->corps_envoye, $m);
        $faux = $m[1] === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/reinitialiser', [
                'telephone' => '22670445566', 'code' => $faux, 'mot_de_passe' => 'nouveaumdp2', 'mot_de_passe_confirmation' => 'nouveaumdp2',
            ])->assertStatus(422);
        }

        $this->postJson('/api/auth/reinitialiser', [
            'telephone' => '22670445566', 'code' => $m[1], 'mot_de_passe' => 'nouveaumdp2', 'mot_de_passe_confirmation' => 'nouveaumdp2',
        ])->assertStatus(422);
    }

    public function test_les_pages_de_compte_s_affichent(): void
    {
        $this->get('/livreur/activer')->assertOk()->assertSee('Code reçu par SMS');
        $this->get('/mot-de-passe-oublie')->assertOk();
        $this->get('/compte/mot-de-passe')->assertOk();
        $this->get('/livreur/connexion')->assertOk()->assertSee('Activer mon compte');
    }

    // -----------------------------------------------------------------
    //  MA BOUTIQUE — compte de paiement
    // -----------------------------------------------------------------

    private function boutiqueEtAdmin(): array
    {
        $pays = $this->creerPays();
        $ville = $this->creerVille($pays);
        ['boutique' => $b, 'utilisateur' => $vendeur] = $this->creerBoutiqueAvecVendeur($pays, ['ville_id' => $ville->id]);
        $op = OperateurPaiement::create(['pays_id' => $pays->id, 'code' => 'orange_bf', 'nom' => 'Orange Money', 'type' => 'mobile_money']);

        return compact('pays', 'ville', 'b', 'vendeur', 'op') + [
            'admin' => $this->creerUtilisateur($pays, ['role' => 'admin']),
            'agent' => $this->creerUtilisateur($pays, ['role' => 'agent']),
        ];
    }

    public function test_changer_de_compte_de_paiement_suspend_les_ventes_jusqu_a_verification(): void
    {
        ['b' => $b, 'vendeur' => $vendeur, 'op' => $op, 'admin' => $admin] = $this->boutiqueEtAdmin();
        $this->assertTrue($b->peutVendre());

        $this->actingAs($vendeur, 'sanctum')->putJson('/api/vendeur/boutique/paiement', [
            'operateur_paiement_id' => $op->id, 'numero' => '70 55 66 77', 'titulaire' => 'Awa Ouédraogo',
        ])->assertOk();

        $b->refresh();
        $this->assertNull($b->paiement_verifie_le);
        $this->assertFalse($b->peutVendre(), 'Un nouveau numéro non vérifié ne doit rien recevoir.');

        // Le vendeur change encore le numéro pendant que l'admin regarde l'ancien.
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/boutiques/{$b->id}/verifier-paiement", [
            'numero' => '70000000', 'confirmation' => true,
        ])->assertStatus(422);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/boutiques/{$b->id}/verifier-paiement", [
            'numero' => '70556677', 'confirmation' => true,
        ])->assertOk();

        $this->assertTrue($b->fresh()->peutVendre());
        $this->assertDatabaseHas('journal_administration', ['action' => 'paiement_verifie', 'cible_id' => $b->id]);
    }

    public function test_le_vendeur_modifie_sa_fiche_mais_pas_dans_une_ville_d_un_autre_pays(): void
    {
        ['b' => $b, 'vendeur' => $vendeur, 'ville' => $ville] = $this->boutiqueEtAdmin();
        $ailleurs = $this->creerPays(['code_iso2' => 'CI', 'nom' => 'Côte d’Ivoire']);
        $abidjan = $this->creerVille($ailleurs, ['nom' => 'Abidjan', 'slug' => 'abidjan']);

        $this->actingAs($vendeur, 'sanctum')->getJson('/api/vendeur/boutique')->assertOk()
            ->assertJsonPath('boutique.peut_vendre', true);

        $this->actingAs($vendeur, 'sanctum')->putJson('/api/vendeur/boutique', [
            'telephone' => '70001122', 'ville_id' => $abidjan->id, 'description' => 'x',
        ])->assertStatus(422);

        $this->actingAs($vendeur, 'sanctum')->putJson('/api/vendeur/boutique', [
            'telephone' => '70001122', 'ville_id' => $ville->id, 'description' => 'Pagnes tissés main.',
            'fermee_du' => now()->toDateString(), 'fermee_au' => now()->addDays(3)->toDateString(),
        ])->assertOk();

        $this->assertTrue($b->fresh()->estFermee());
        $this->assertFalse($b->fresh()->peutVendre());
    }

    public function test_suspendre_et_reactiver_une_boutique_et_identification(): void
    {
        ['b' => $b, 'admin' => $admin, 'agent' => $agent] = $this->boutiqueEtAdmin();
        $motif = ['motif' => 'Plaintes répétées de clients.'];

        $this->actingAs($agent, 'sanctum')->postJson("/api/admin/boutiques/{$b->id}/suspendre", $motif)->assertForbidden();

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/boutiques/{$b->id}/suspendre", $motif)->assertOk();
        $this->assertSame('suspendu', $b->fresh()->statut);
        $this->assertFalse($b->fresh()->peutVendre());

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/boutiques/{$b->id}/reactiver", ['motif' => 'Situation régularisée.'])->assertOk();
        $this->assertSame('actif', $b->fresh()->statut);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/boutiques/{$b->id}/identification", [
            'niveau' => 'complet', 'motif' => 'CNIB et registre de commerce vus.',
        ])->assertOk();
        $this->assertSame('complet', $b->utilisateur->fresh()->kyc_niveau);

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/boutiques?filtre=tous')->assertOk()->assertJsonPath('data.0.id', $b->id);
    }
}
