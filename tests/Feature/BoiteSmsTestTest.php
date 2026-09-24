<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La boîte SMS de test affiche des codes qui libèrent de l'argent :
 * chacun de ses verrous est vérifié ici, un par un.
 */
class BoiteSmsTestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Conditions normales d'un poste de développement.
        $this->app['env'] = 'local';
        config()->set('afrishop.sms.driver', 'journal');

        app(NotificationService::class)->envoyer('code_livraison', 'sms', [
            'reference' => 'BF-CMD-TEST-1', 'code' => '482913',
        ], telephone: '22670001122');
    }

    /**
     * Hors de « testing », Laravel vérifie de nouveau le jeton CSRF : on
     * envoie donc un vrai jeton, comme le ferait le formulaire de la page.
     */
    private function envoyerLaFile()
    {
        return $this->withSession(['_token' => 'jeton-test'])
            ->post('/dev/sms/envoyer', ['_token' => 'jeton-test']);
    }

    public function test_la_boite_montre_le_sms_en_clair_avec_le_code(): void
    {
        $this->get('/dev/sms')
            ->assertOk()
            ->assertSee('22670001122')
            ->assertSee('BF-CMD-TEST-1')
            ->assertSee('<span class="code">482913</span>', false);
    }

    public function test_le_filtre_par_telephone(): void
    {
        $this->get('/dev/sms?telephone=70001122')->assertOk()->assertSee('482913');
        $this->get('/dev/sms?telephone=79999999')->assertOk()->assertDontSee('482913');
    }

    public function test_le_bouton_envoie_la_file(): void
    {
        $this->envoyerLaFile()->assertRedirect('/dev/sms');

        $this->assertSame('envoye', Notification::firstOrFail()->statut);
    }

    public function test_introuvable_hors_environnement_local(): void
    {
        $this->app['env'] = 'staging';

        $this->get('/dev/sms')->assertNotFound();
        $this->envoyerLaFile()->assertNotFound();
        $this->assertSame('en_file', Notification::firstOrFail()->statut);
    }

    public function test_introuvable_des_qu_un_vrai_operateur_sms_est_branche(): void
    {
        config()->set('afrishop.sms.driver', 'orange');

        $this->get('/dev/sms')->assertNotFound();
    }

    public function test_introuvable_depuis_une_autre_machine(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])
            ->get('/dev/sms')->assertNotFound();
    }

    public function test_introuvable_a_travers_un_tunnel(): void
    {
        // cloudflared fait arriver la requête depuis 127.0.0.1, mais pose cet en-tête.
        $this->withHeaders(['CF-Connecting-IP' => '41.138.10.5'])
            ->get('/dev/sms')->assertNotFound();
    }
}
