<?php

namespace Tests\Unit\Services;

use App\Models\Commande;
use App\Models\Litige;
use App\Models\Retour;
use App\Models\SousCommande;
use App\Services\SequestreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreeDonneesTrait;
use Tests\TestCase;

class SequestreServiceTest extends TestCase
{
    use RefreshDatabase, CreeDonneesTrait;

    private function creerSousCommande(array $attrs = []): SousCommande
    {
        $pays = $this->creerPays();
        ['boutique' => $boutique] = $this->creerBoutiqueAvecVendeur($pays);

        $commande = Commande::create([
            'reference'        => 'BF-CMD-2026-' . random_int(100000, 999999),
            'pays_id'          => $pays->id,
            'client_nom'       => 'Client Test',
            'client_telephone' => '22670000000',
            'quartier'         => 'Zone 1',
            'mode_paiement'    => 'especes_livraison',
        ]);

        return $commande->sousCommandes()->create(array_merge([
            'boutique_id'         => $boutique->id,
            'reference'           => 'REF-' . random_int(100000, 999999),
            'statut'              => 'livree',
            'etat_fonds'          => 'sequestre',
            'taux_commission_pct' => 12,
            'livre_le'            => now()->subDays(5),
        ], $attrs));
    }

    public function test_non_livree_nest_pas_liberable(): void
    {
        $sc = $this->creerSousCommande(['statut' => 'expediee', 'livre_le' => null]);

        $this->assertFalse(app(SequestreService::class)->liberables($sc));
    }

    public function test_fonds_pas_en_sequestre_nest_pas_liberable(): void
    {
        $sc = $this->creerSousCommande(['etat_fonds' => 'attente_encaissement']);

        $this->assertFalse(app(SequestreService::class)->liberables($sc));
    }

    public function test_litige_ouvert_bloque_la_liberation(): void
    {
        $sc = $this->creerSousCommande();
        Litige::create([
            'sous_commande_id' => $sc->id, 'reference' => 'LIT-' . random_int(1000, 9999),
            'motif' => 'non_recu', 'description' => 'Colis jamais reçu', 'statut' => 'ouvert',
        ]);

        $this->assertFalse(app(SequestreService::class)->liberables($sc));
    }

    public function test_retour_en_cours_bloque_la_liberation(): void
    {
        $sc = $this->creerSousCommande();
        Retour::create([
            'sous_commande_id' => $sc->id, 'reference' => 'RET-' . random_int(1000, 9999),
            'type' => 'retractation', 'motif' => 'ne_convient_pas', 'statut' => 'demande',
        ]);

        $this->assertFalse(app(SequestreService::class)->liberables($sc));
    }

    public function test_confirmation_client_rend_liberable_immediatement(): void
    {
        $sc = $this->creerSousCommande(['livre_le' => now(), 'confirme_par_client_le' => now()]);

        $this->assertTrue(app(SequestreService::class)->liberables($sc));
    }

    public function test_delai_ecoule_sans_confirmation_rend_liberable(): void
    {
        // Délai par défaut : 3 jours. Livré il y a 5 jours, jamais confirmé.
        $sc = $this->creerSousCommande(['livre_le' => now()->subDays(5), 'confirme_par_client_le' => null]);

        $this->assertTrue(app(SequestreService::class)->liberables($sc));
    }

    public function test_delai_non_ecoule_sans_confirmation_nest_pas_liberable(): void
    {
        $sc = $this->creerSousCommande(['livre_le' => now()->subDay(), 'confirme_par_client_le' => null]);

        $this->assertFalse(app(SequestreService::class)->liberables($sc));
    }

    public function test_liberer_ecrit_letat_fonds_et_journalise(): void
    {
        $sc = $this->creerSousCommande(['livre_le' => now()->subDays(5)]);

        app(SequestreService::class)->liberer($sc);

        $this->assertSame('reverse', $sc->fresh()->etat_fonds->value);
        $this->assertDatabaseHas('evenements_commande', [
            'sous_commande_id' => $sc->id, 'type' => 'fonds_liberes',
        ]);
    }
}
