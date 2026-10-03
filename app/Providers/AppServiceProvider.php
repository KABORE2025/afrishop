<?php

namespace App\Providers;

use App\Services\Paiement\CinetPayGateway;
use App\Services\Paiement\FakeGateway;
use App\Services\Paiement\PayDunyaGateway;
use App\Services\Paiement\PaymentGatewayInterface;
use App\Services\Sms\PasserelleJournal;
use App\Services\Sms\PasserelleOrange;
use App\Services\Sms\PasserelleSmsInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
                    apiKey: (string) config('afrishop.psp.cinetpay.api_key'),
                    siteId: (string) config('afrishop.psp.cinetpay.site_id'),
                    secretKey: (string) config('afrishop.psp.cinetpay.secret_key'),
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
                    urlRetour: url('/'),
                    canaux: (string) config('afrishop.psp.cinetpay.canaux'),
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
                    masterKey: (string) config('afrishop.psp.paydunya.master_key'),
                    privateKey: (string) config('afrishop.psp.paydunya.private_key'),
                    token: (string) config('afrishop.psp.paydunya.token'),
                    urlNotification: url('/api/webhooks/paiement/paydunya'),
                    urlRetour: url('/'),
                    urlAnnulation: url('/panier'),
                    nomBoutique: (string) config('afrishop.psp.paydunya.nom_boutique'),
                    mode: (string) config('afrishop.psp.paydunya.mode'),
                ),

                default => new FakeGateway,
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
                    clientId: (string) config('afrishop.sms.client_id'),
                    clientSecret: (string) config('afrishop.sms.client_secret'),
                    adresseExpediteur: (string) config('afrishop.sms.adresse_expediteur'),
                    nomExpediteur: (string) config('afrishop.sms.nom_expediteur'),
                    coutUnitaireCfa: (int) config('afrishop.sms.cout_unitaire_cfa'),
                ),
                default => new PasserelleJournal,
            };
        });
    }

    /**
     * =================================================================
     *  AUTORITÉS DE CERTIFICATION — le fichier qui manquait à PHP
     * =================================================================
     *  LE PROBLÈME. Sous Windows, PHP est livré SANS liste d'autorités
     *  de certification. Il ne peut donc vérifier aucun certificat
     *  HTTPS et refuse toute connexion sécurisée, avec ce message :
     *
     *      cURL error 60: SSL certificate — unable to get local
     *      issuer certificate
     *
     *  Cela bloquait tout appel sortant : PayDunya, CinetPay, l'API SMS
     *  d'Orange. La commande était créée, le paiement jamais lancé.
     *
     *  LA CORRECTION CLASSIQUE est de renseigner `curl.cainfo` dans
     *  `php.ini`. Elle est bonne, mais elle vit sur le poste et pas
     *  dans le dépôt : chaque nouvelle machine, chaque serveur, chaque
     *  collègue retombe sur la même panne.
     *
     *  Le paquet est donc embarqué dans `storage/certs/cacert.pem` et
     *  déclaré ici. La correction voyage avec le code.
     *
     *  CE N'EST PAS UNE DÉSACTIVATION DE LA VÉRIFICATION.
     *  On ne met pas `verify => false` — cela reviendrait à accepter
     *  n'importe quel certificat, donc à laisser quiconque se placer
     *  entre le serveur et le prestataire de paiement, lire les clés et
     *  détourner les confirmations d'encaissement. On FOURNIT la liste
     *  des autorités de confiance : la vérification reste entière,
     *  elle sait simplement à quoi se référer.
     *
     *  `CA_BUNDLE=` vide dans le `.env` rend la main au système, pour
     *  un serveur Linux correctement configuré dont le magasin est
     *  déjà à jour — ce qui est préférable, un paquet versionné
     *  finissant toujours par vieillir.
     * =================================================================
     */
    public function boot(): void
    {
        $paquet = (string) config('afrishop.ca_bundle');

        if ($paquet !== '' && is_file($paquet)) {
            Http::globalOptions(['verify' => $paquet]);
        }

        $this->definirLimitesDeTentatives();
    }

    /**
     * =================================================================
     *  LIMITES DE TENTATIVES
     * =================================================================
     *  Seule la vérification QR était limitée. Tout le reste acceptait
     *  un nombre illimité d'essais : mots de passe à la connexion,
     *  couples référence/téléphone au suivi, et surtout le code à six
     *  chiffres d'activation des agents — un million de combinaisons,
     *  qu'un script parcourt en quelques heures.
     *
     *  Chaque limite compte PAR ADRESSE IP, et pour les secrets PAR
     *  CIBLE (téléphone, CNIB) : changer d'IP ne donne pas de nouveaux
     *  essais sur le même compte. Réponse : HTTP 429 avec l'en-tête
     *  Retry-After.
     *
     *  Les valeurs laissent de la marge aux usages réels : sur un même
     *  point d'accès (cybercafé, antenne 3G partagée), plusieurs
     *  clients légitimes sortent par la même IP.
     * =================================================================
     */
    private function definirLimitesDeTentatives(): void
    {
        $chiffres = fn (?string $v) => preg_replace('/\D/', '', (string) $v);

        // Mot de passe oublié : chaque demande envoie un SMS payant, et le
        // code se devine en essayant. Par IP ET par numéro.
        RateLimiter::for('reinitialisation', fn (Request $r) => [
            Limit::perMinute(5)->by('reinit-ip:'.$r->ip()),
            Limit::perHour(5)->by('reinit-tel:'.$chiffres($r->input('telephone'))),
        ]);

        RateLimiter::for('connexion', fn (Request $r) => [
            Limit::perMinute(5)->by('connexion:'.$chiffres($r->input('telephone')).'|'.$r->ip()),
            Limit::perHour(20)->by('connexion-compte:'.$chiffres($r->input('telephone'))),
            Limit::perMinute(30)->by('connexion-ip:'.$r->ip()),
        ]);

        RateLimiter::for('inscription', fn (Request $r) => Limit::perHour(10)->by('inscription:'.$r->ip()));

        RateLimiter::for('formulaire', fn (Request $r) => Limit::perHour(10)->by('formulaire:'.$r->ip()));

        RateLimiter::for('suivi', fn (Request $r) => [
            Limit::perMinute(20)->by('suivi:'.$r->ip()),
            Limit::perHour(10)->by('suivi-ref:'.Str::upper((string) $r->input('reference'))),
        ]);

        RateLimiter::for('commande', fn (Request $r) => Limit::perMinute(10)->by('commande:'.$r->ip()));

        // Saisie du code de livraison : 10 essais par minute et par livreur.
        // Largement assez pour une vraie remise, trop peu pour deviner.
        RateLimiter::for('code-livraison', fn (Request $r) => Limit::perMinute(10)
            ->by('code-livraison:'.($r->user()?->id ?? $r->ip())));

        // Le téléphone vérifie ces gestes : sans limite, on le devinerait
        // chiffre par chiffre sur une référence connue.
        RateLimiter::for('protection', fn (Request $r) => [
            Limit::perMinute(5)->by('protection:'.$r->ip()),
            Limit::perHour(10)->by('protection-ref:'.Str::upper((string) $r->route('reference'))),
        ]);

        RateLimiter::for('activation-agent', fn (Request $r) => [
            Limit::perMinute(5)->by('activation:'.$r->ip()),
            Limit::perHour(5)->by('activation-cnib:'.Str::upper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $r->input('cnib')))),
        ]);
    }
}
