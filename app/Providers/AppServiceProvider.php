<?php

namespace App\Providers;

use App\Services\Paiement\CinetPayGateway;
use App\Services\Paiement\FakeGateway;
use App\Services\Paiement\PayDunyaGateway;
use App\Services\Paiement\PaymentGatewayInterface;
use App\Services\Sms\PasserelleJournal;
use App\Services\Sms\PasserelleOrange;
use App\Services\Sms\PasserelleSmsInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * La passerelle de paiement est résolue ici, à un seul endroit.
     * Brancher un vrai PSP demain consiste à ajouter un cas au match()
     * ci-dessous — rien d'autre dans l'application ne dépend du driver
     * concret, seulement de l'interface.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGatewayInterface::class, function () {
            return match (config('afrishop.psp.driver')) {
                'cinetpay' => new CinetPayGateway(
                    apiKey:          (string) config('afrishop.psp.cinetpay.api_key'),
                    siteId:          (string) config('afrishop.psp.cinetpay.site_id'),
                    secretKey:       (string) config('afrishop.psp.cinetpay.secret_key'),
                    /*
                     * Les deux URL sont construites à partir d'APP_URL.
                     * CinetPay doit pouvoir ATTEINDRE `notify_url` depuis
                     * Internet : en local, 127.0.0.1 ne lui dit rien, et
                     * aucune notification n'arrivera jamais. Pour tester
                     * sur son poste, exposer le site par un tunnel
                     * (ngrok, cloudflared) et mettre cette adresse
                     * publique dans APP_URL.
                     */
                    urlNotification: url('/api/webhooks/paiement/cinetpay'),
                    urlRetour:       url('/'),
                    canaux:          (string) config('afrishop.psp.cinetpay.canaux'),
                ),
                /*
                 * PayDunya a un vrai bac à sable : c'est le seul
                 * prestataire avec lequel toute la chaîne — page de
                 * paiement, notification, grand livre, séquestre — se
                 * vérifie sans engagement commercial et sans qu'un
                 * franc bouge. `PAYDUNYA_MODE` bascule test/production
                 * sans toucher au code.
                 */
                'paydunya' => new PayDunyaGateway(
                    masterKey:       (string) config('afrishop.psp.paydunya.master_key'),
                    privateKey:      (string) config('afrishop.psp.paydunya.private_key'),
                    token:           (string) config('afrishop.psp.paydunya.token'),
                    urlNotification: url('/api/webhooks/paiement/paydunya'),
                    urlRetour:       url('/'),
                    urlAnnulation:   url('/panier'),
                    nomBoutique:     (string) config('afrishop.psp.paydunya.nom_boutique'),
                    mode:            (string) config('afrishop.psp.paydunya.mode'),
                ),

                default => new FakeGateway(),
            };
        });

        /*
         * Même principe pour le SMS. « journal » est le driver par
         * défaut : il n'envoie rien, ce qui est le bon comportement tant
         * qu'aucun contrat opérateur n'est signé — mieux vaut ne rien
         * envoyer que d'échouer silencieusement sur chaque message.
         */
        $this->app->bind(PasserelleSmsInterface::class, function () {
            return match (config('afrishop.sms.driver')) {
                'orange' => new PasserelleOrange(
                    clientId:           (string) config('afrishop.sms.client_id'),
                    clientSecret:       (string) config('afrishop.sms.client_secret'),
                    adresseExpediteur:  (string) config('afrishop.sms.adresse_expediteur'),
                    nomExpediteur:      (string) config('afrishop.sms.nom_expediteur'),
                    coutUnitaireCfa:    (int)    config('afrishop.sms.cout_unitaire_cfa'),
                ),
                default => new PasserelleJournal(),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
