<?php

namespace App\Console\Commands;

use App\Services\Sms\EnvoiSmsService;
use Illuminate\Console\Command;

/**
 * =====================================================================
 *  afrishop:envoyer-notifications
 * =====================================================================
 *  Draine la file des SMS en attente. À planifier toutes les minutes.
 *
 *  ⚠ MÊME AVERTISSEMENT QUE POUR afrishop:liberer-fonds :
 *  si cette commande s'arrête, les clients ne reçoivent plus leur code
 *  de livraison, les livreurs ne peuvent plus faire valider les
 *  livraisons, et RIEN NE LE SIGNALE. La file grossit en silence.
 *  À superviser au même titre que la libération des fonds.
 * =====================================================================
 */
class EnvoyerNotifications extends Command
{
    protected $signature = 'afrishop:envoyer-notifications {--limite=100 : Nombre maximum de messages par passage}';

    protected $description = 'Envoie les notifications SMS en file d\'attente';

    public function handle(EnvoiSmsService $service): int
    {
        $bilan = $service->drainer((int) $this->option('limite'));

        if ($bilan['traitees'] === 0) {
            $this->info('Aucune notification en attente.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d traitée(s) — %d envoyée(s), %d en échec — %d FCFA.',
            $bilan['traitees'], $bilan['envoyees'], $bilan['echouees'], $bilan['cout_cfa']
        ));

        // Un échec isolé arrive (numéro invalide). Une majorité d'échecs
        // veut dire que le solde est épuisé ou que les identifiants sont
        // faux — ce n'est plus un incident de donnée, c'est une panne.
        if ($bilan['echouees'] > $bilan['envoyees']) {
            $this->error('Plus d\'échecs que d\'envois : vérifier le solde SMS et les identifiants.');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
