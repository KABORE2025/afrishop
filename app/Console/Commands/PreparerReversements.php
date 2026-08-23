<?php

namespace App\Console\Commands;

use App\Services\ReversementService;
use Illuminate\Console\Command;

/**
 * Tâche hebdomadaire : regroupe les ventes libérées en un reversement
 * par boutique.
 *
 * À planifier dans routes/console.php :
 *   Schedule::command('afrishop:preparer-reversements')->weeklyOn(1, '07:00');
 *
 * Sans elle, les ventes s'accumulent en état « reverse » sans jamais
 * être regroupées en un virement — les vendeurs voient leur solde
 * augmenter sans être payés.
 */
class PreparerReversements extends Command
{
    protected $signature = 'afrishop:preparer-reversements {--jours=7}';
    protected $description = 'Regroupe les ventes libérées en un reversement par boutique, sur les N derniers jours';

    public function handle(ReversementService $reversements): int
    {
        $jours = (int) $this->option('jours');
        $fin = now();
        $debut = $fin->clone()->subDays($jours);

        $this->info("Préparation des reversements du {$debut->toDateString()} au {$fin->toDateString()}…");

        $prepares = $reversements->preparerPeriode($debut, $fin);

        $this->info(count($prepares) . ' reversement(s) préparé(s).');

        logger()->info('afrishop:preparer-reversements', ['nombre' => count($prepares)]);

        return self::SUCCESS;
    }
}
