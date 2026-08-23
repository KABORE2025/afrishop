<?php

namespace App\Providers;

use App\Services\Paiement\FakeGateway;
use App\Services\Paiement\PaymentGatewayInterface;
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
    }

    public function boot(): void
    {
        //
    }
}
