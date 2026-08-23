<?php

namespace App\Console\Commands;

use App\Models\PrestatairePaiement;
use App\Services\ReconciliationPspService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Tâche quotidienne : calcule le volet local de la réconciliation PSP.
 *
 * À planifier dans routes/console.php :
 *   Schedule::command('afrishop:reconcilier-psp')->dailyAt('05:30');
 *
 * Ne calcule QUE ce que la plateforme peut connaître seule (voir
 * ReconciliationPspService). Le rapprochement complet avec le
 * prestataire attend un connecteur réel.
 */
class ReconcilierPsp extends Command
{
    protected $signature = 'afrishop:reconcilier-psp {--date=}';
    protected $description = "Calcule le volet local de la réconciliation PSP du jour, prestataire par prestataire";

    public function handle(ReconciliationPspService $reconciliation): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : now()->subDay();

        $prestataires = PrestatairePaiement::where('actif', true)->get();

        foreach ($prestataires as $prestataire) {
            $reconciliation->enregistrerLocal($prestataire->id, $date);
        }

        $this->info("Volet local calculé pour {$prestataires->count()} prestataire(s) au {$date->toDateString()}.");

        logger()->info('afrishop:reconcilier-psp', [
            'date' => $date->toDateString(),
            'prestataires' => $prestataires->count(),
        ]);

        return self::SUCCESS;
    }
}
