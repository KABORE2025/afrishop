<?php

namespace Tests\Feature\Api;

use App\Models\AgentRemiseBoutique;
use App\Models\SousCommande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

/**
 * L'admin peut consulter et faire renvoyer le code de remise (livraison
 * ou retrait boutique) d'une sous-commande — jamais le vendeur ni le
 * livreur, qui doivent rester ignorants du code qu'ils sont censés
 * vérifier. Chaque accès exige un motif et laisse une double trace.
 */
class AdminCodeRemiseTest extends TestCase
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

        $sousCommande = SousCommande::where('boutique_id', $boutique->id)->firstOrFail();
        $this->actingAs($vendeur, 'sanctum')->postJson("/api/vendeur/commandes/{$sousCommande->id}/preparer-retrait");

        $admin = $this->creerUtilisateur($pays, ['role' => 'admin']);

        return compact('pays', 'admin', 'sousCommande');
    }

    private function creerContexteDomicile(): array
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

        $sousCommande = SousCommande::where('boutique_id', $boutique->id)->firstOrFail();

        $livreur = $this->creerUtilisateur($pays, ['role' => 'livreur', 'ville_id' => $ville->id]);
        AgentRemiseBoutique::create([
            'boutique_id' => $boutique->id, 'livreur_id' => $livreur->id,
            'statut' => 'actif', 'autorise_livraison' => true,
            'invite_par_utilisateur_id' => $vendeur->id, 'accepte_le' => now(),
        ]);
        $this->actingAs($vendeur, 'sanctum')
            ->postJson("/api/vendeur/commandes/{$sousCommande->id}/expedier", ['livreur_id' => $livreur->id]);

        $admin = $this->creerUtilisateur($pays, ['role' => 'admin']);

        return compact('pays', 'admin', 'sousCommande');
    }

    public function test_admin_consulte_le_code_de_retrait_avec_motif(): void
    {
        ['admin' => $admin, 'sousCommande' => $sc] = $this->creerContexteRetrait();
        $codeAttendu = $sc->fresh()->code_retrait;

        $reponse = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/sous-commandes/{$sc->id}/code-remise?motif=" . urlencode('Client injoignable, litige colis.'));

        $reponse->assertOk()->assertJson([
            'mode_livraison' => 'retrait_boutique',
            'code' => $codeAttendu,
            'deja_valide' => false,
        ]);

        $this->assertDatabaseHas('evenements_commande', [
            'sous_commande_id' => $sc->id, 'type' => 'code_consulte', 'auteur_type' => 'admin', 'auteur_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('journal_administration', [
            'agent_id' => $admin->id, 'action' => 'code_consulte',
            'cible_type' => 'sous_commande', 'cible_id' => $sc->id,
        ]);
    }

    public function test_admin_consulte_le_code_de_livraison_domicile(): void
    {
        ['admin' => $admin, 'sousCommande' => $sc] = $this->creerContexteDomicile();
        $codeAttendu = $sc->fresh()->expedition->code_livraison;

        $reponse = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/sous-commandes/{$sc->id}/code-remise?motif=" . urlencode('Client a perdu son téléphone.'));

        $reponse->assertOk()->assertJson([
            'mode_livraison' => 'domicile',
            'code' => $codeAttendu,
        ]);
    }

    public function test_consultation_sans_motif_est_refusee(): void
    {
        ['admin' => $admin, 'sousCommande' => $sc] = $this->creerContexteRetrait();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/sous-commandes/{$sc->id}/code-remise")
            ->assertStatus(422);
    }

    public function test_vendeur_ne_peut_pas_consulter_le_code(): void
    {
        ['sousCommande' => $sc] = $this->creerContexteRetrait();
        $vendeur = $sc->boutique->utilisateur;

        $this->actingAs($vendeur, 'sanctum')
            ->getJson("/api/admin/sous-commandes/{$sc->id}/code-remise?motif=peu+importe")
            ->assertStatus(403);
    }

    public function test_admin_fait_renvoyer_le_meme_code_de_retrait(): void
    {
        ['admin' => $admin, 'sousCommande' => $sc] = $this->creerContexteRetrait();
        $codeAvant = $sc->fresh()->code_retrait;

        $reponse = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/sous-commandes/{$sc->id}/code-remise/renvoyer", [
                'motif' => 'Client dit ne pas avoir reçu le SMS.',
            ]);

        $reponse->assertOk();
        // Le code ne change JAMAIS lors d'un renvoi.
        $this->assertSame($codeAvant, $sc->fresh()->code_retrait);
        $this->assertDatabaseHas('journal_administration', [
            'agent_id' => $admin->id, 'action' => 'code_renvoye', 'cible_id' => $sc->id,
        ]);
    }

    public function test_renvoi_refuse_si_deja_livree(): void
    {
        ['admin' => $admin, 'sousCommande' => $sc] = $this->creerContexteRetrait();
        $sc->update(['statut' => 'livree']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/sous-commandes/{$sc->id}/code-remise/renvoyer", [
                'motif' => 'Client dit ne pas avoir reçu le SMS.',
            ])
            ->assertStatus(422);
    }
}
