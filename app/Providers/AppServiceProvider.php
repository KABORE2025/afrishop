<?php

namespace App\Providers;

use App\Services\Paiement\FakeGateway;
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
