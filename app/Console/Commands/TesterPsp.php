<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * =====================================================================
 *  DIAGNOSTIC DE LA PASSERELLE DE PAIEMENT
 * =====================================================================
 *  POURQUOI CETTE COMMANDE EXISTE. Quand un paiement échoue, la cause
 *  peut être n'importe où : clé mal recopiée, mauvais champ pris dans
 *  le tableau de bord, mode test contre clés de production, certificat
 *  TLS absent, réseau qui bloque. Le client, lui, ne voit qu'un
 *  « paiement refusé », et il faut fouiller le journal pour en savoir
 *  plus — en ayant d'abord passé une vraie commande.
 *
 *  Cette commande interroge le prestataire DIRECTEMENT, sans commande
 *  ni panier, et affiche sa réponse brute. Elle transforme une demi-
 *  heure de tâtonnements en une réponse immédiate.
 *
 *  AUCUN SECRET N'EST AFFICHÉ. Seuls la longueur et les premiers
 *  caractères de chaque clé sont montrés : assez pour voir qu'on a
 *  collé le mauvais champ ou laissé un espace, jamais assez pour que
 *  la clé fuite dans une capture d'écran ou un ticket de support.
 *
 *  UTILISATION :
 *    php artisan afrishop:tester-psp
 * =====================================================================
 */
class TesterPsp extends Command
{
    protected $signature = 'afrishop:tester-psp';

    protected $description = 'Vérifie les identifiants du prestataire de paiement et sa joignabilité';

    public function handle(): int
    {
        $driver = (string) config('afrishop.psp.driver');

        $this->newLine();
        $this->info("Driver actif : {$driver}");

        if ($driver === 'fake') {
            $this->warn('Le driver « fake » encaisse tout sans rien envoyer. Rien à tester.');
            $this->line('Passez PSP_DRIVER=paydunya (ou cinetpay) dans le .env, puis php artisan config:clear.');

            return self::SUCCESS;
        }

        if ($driver !== 'paydunya') {
            $this->warn("Diagnostic disponible pour « paydunya » seulement pour l'instant.");

            return self::SUCCESS;
        }

        return $this->testerPayDunya();
    }

    private function testerPayDunya(): int
    {
        $mode = (string) config('afrishop.psp.paydunya.mode');
        $master = (string) config('afrishop.psp.paydunya.master_key');
        $private = (string) config('afrishop.psp.paydunya.private_key');
        $token = (string) config('afrishop.psp.paydunya.token');

        // ---------------------------------------------------------------
        //  1. Ce que Laravel a réellement lu
        // ---------------------------------------------------------------
        $this->newLine();
        $this->line('<options=bold>Clés lues dans le .env</>');
        $this->table(
            ['Variable', 'Longueur', 'Début', 'Fin', 'Espaces ?'],
            [
                $this->ligne('PAYDUNYA_MASTER_KEY', $master),
                $this->ligne('PAYDUNYA_PRIVATE_KEY', $private),
                $this->ligne('PAYDUNYA_TOKEN', $token),
            ],
        );

        $this->line("Mode : <options=bold>{$mode}</>");

        /*
         * En mode test, PayDunya préfixe la clé privée par « test_ ».
         * Une clé privée sans ce préfixe en mode test signifie qu'on a
         * copié la section Production — erreur si banale qu'elle mérite
         * d'être nommée plutôt que laissée à deviner.
         */
        if ($mode === 'test' && $private !== '' && ! str_starts_with($private, 'test_')) {
            $this->warn('  La clé privée ne commence pas par « test_ » alors que le mode est « test ».');
            $this->warn('  Vous avez probablement copié la section « Clés API de Production ».');
        }

        if ($mode === 'live' && str_starts_with($private, 'test_')) {
            $this->error('  Clé privée de TEST avec le mode « live ». Aucun paiement réel ne passera.');
        }

        foreach (['PAYDUNYA_MASTER_KEY' => $master, 'PAYDUNYA_PRIVATE_KEY' => $private, 'PAYDUNYA_TOKEN' => $token] as $nom => $valeur) {
            if (trim($valeur) === '') {
                $this->error("  {$nom} est vide. Vérifiez le .env, puis php artisan config:clear.");

                return self::FAILURE;
            }
            if ($valeur !== trim($valeur)) {
                $this->error("  {$nom} contient un espace en début ou en fin — PayDunya la rejettera.");
            }
        }

        // ---------------------------------------------------------------
        //  2. Un appel réel, avec une facture de 100 F jamais payée
        // ---------------------------------------------------------------
        $base = $mode === 'live'
            ? 'https://app.paydunya.com/api/v1'
            : 'https://app.paydunya.com/sandbox-api/v1';

        $this->newLine();
        $this->line("<options=bold>Appel de {$base}/checkout-invoice/create</>");

        try {
            $reponse = Http::timeout(20)
                ->withHeaders([
                    'PAYDUNYA-MASTER-KEY' => $master,
                    'PAYDUNYA-PRIVATE-KEY' => $private,
                    'PAYDUNYA-TOKEN' => $token,
                ])
                ->acceptJson()->asJson()
                ->post($base.'/checkout-invoice/create', [
                    'invoice' => ['total_amount' => 100, 'description' => 'Test de configuration Afrishop'],
                    'store' => ['name' => (string) config('afrishop.psp.paydunya.nom_boutique')],
                ]);
        } catch (\Throwable $e) {
            $this->error('  Injoignable : '.$e->getMessage());
            $this->newLine();
            $this->line('  Si le message parle de « SSL certificate » ou « cURL error 60 », le paquet');
            $this->line('  d\'autorités de certification n\'est pas chargé — voir config afrishop.ca_bundle.');

            return self::FAILURE;
        }

        $corps = $reponse->json() ?? [];
        $code = $corps['response_code'] ?? '(aucun)';
        $texte = $corps['response_text'] ?? '(aucun)';

        $this->newLine();

        if ($code === '00') {
            $this->info('  ✓ Identifiants valides. PayDunya a créé une facture de test.');
            $this->line('  Page de paiement : '.$texte);
            $this->newLine();
            $this->line('  Cette facture ne sera jamais payée : elle expirera d\'elle-même.');
            $this->line('  Vous pouvez passer une vraie commande sur le site.');

            return self::SUCCESS;
        }

        $this->error("  ✗ Refus de PayDunya — code {$code}");
        $this->error("     {$texte}");
        $this->newLine();

        /*
         * Traduction des refus les plus fréquents. Le code brut de
         * PayDunya ne dit pas QUOI CORRIGER, et c'est pourtant la
         * seule chose qui intéresse à ce moment-là.
         */
        $this->line(match ((string) $code) {
            '1001' => "  La CLÉ PRINCIPALE est rejetée.\n"
                    ."  Elle se trouve en HAUT de la page de votre application, dans\n"
                    ."  « Informations sur l'application » — libellée « Clé Principale ».\n"
                    ."  Ce n'est NI la Clé Publique, NI une clé des sections Test ou Production.\n"
                    ."  Utilisez le bouton « Copier » : la valeur est masquée à l'écran.",

            '1002' => "  La CLÉ PRIVÉE est rejetée. Prenez-la dans « Clés API de Test »\n"
                    .'  si PAYDUNYA_MODE=test — elle doit commencer par « test_ ».',

            '1003' => '  Le TOKEN est rejeté. Il est dans la même section que la clé privée.',

            default => '  Consultez https://developers.paydunya.com/doc/FR/http_json',
        });

        return self::FAILURE;
    }

    /** Une ligne de diagnostic qui ne révèle pas la clé. */
    private function ligne(string $nom, string $valeur): array
    {
        if ($valeur === '') {
            return [$nom, 0, '—', '—', '—'];
        }

        return [
            $nom,
            mb_strlen($valeur),
            mb_substr($valeur, 0, 4).'…',
            '…'.mb_substr($valeur, -4),
            $valeur !== trim($valeur) ? 'OUI — à corriger' : 'non',
        ];
    }
}
