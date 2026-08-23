<?php

namespace Tests\Unit\Services;

use App\Models\BaremeCommission;
use App\Services\BaremeCommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BaremeCommissionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Le cache est partagé entre les tests du même processus : sans
        // le vider, un test pollue le suivant avec un barème périmé.
        Cache::flush();

        BaremeCommission::insert([
            ['applique_a' => 'service', 'plafond_cfa' => 500_000, 'taux_pct' => 10, 'ordre' => 1, 'en_vigueur_du' => '2020-01-01', 'en_vigueur_au' => null],
            ['applique_a' => 'service', 'plafond_cfa' => 5_000_000, 'taux_pct' => 5, 'ordre' => 2, 'en_vigueur_du' => '2020-01-01', 'en_vigueur_au' => null],
            ['applique_a' => 'service', 'plafond_cfa' => null, 'taux_pct' => 1, 'ordre' => 3, 'en_vigueur_du' => '2020-01-01', 'en_vigueur_au' => null],
        ]);
    }

    public function test_commission_degressive_sur_le_chantier_de_25_millions(): void
    {
        $service = app(BaremeCommissionService::class);

        // Exemple du docblock du service : 50 000 + 225 000 + 200 000 = 475 000 F.
        $this->assertSame(475_000, $service->commission(25_000_000));
        $this->assertSame(1.9, $service->tauxMoyen(25_000_000));
    }

    public function test_petit_montant_reste_dans_la_premiere_tranche(): void
    {
        $service = app(BaremeCommissionService::class);

        $this->assertSame(7_500, $service->commission(75_000));
    }

    public function test_le_detail_ventile_chaque_tranche_traversee(): void
    {
        $service = app(BaremeCommissionService::class);

        $detail = $service->detail(25_000_000);

        $this->assertCount(3, $detail['lignes']);
        $this->assertSame(475_000, $detail['commission']);
        $this->assertSame(50_000, $detail['lignes'][0]['montant']);
        $this->assertSame(225_000, $detail['lignes'][1]['montant']);
        $this->assertSame(200_000, $detail['lignes'][2]['montant']);
    }
}
