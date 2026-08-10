<?php

namespace App\Console\Commands;

use App\Services\SequestreService;
use Illuminate\Console\Command;

/**
 * Tâche quotidienne : débloque les livraisons non contestées.
 *
 * À planifier dans routes/console.php :
 *   Schedule::command('afrishop:liberer-fonds')->dailyAt('06:00');
 *
 * Sans cette tâche, l'argent d'un vendeur reste bloqué indéfiniment dès
 * qu'un client oublie de cliquer sur « J'ai bien reçu » — ce qui est le
 * cas de la grande majorité des clients. C'est la commande la plus
 * importante du système : si elle cesse de tourner, les vendeurs ne sont
 * plus payés et personne ne s'en aperçoit avant leurs réclamations.
 */
class LibererLesFonds extends Command
{
    protected $signature = 'afrishop:liberer-fonds';
    protected $description = 'Débloque les fonds des livraisons non contestées dont le délai est écoulé';

    public function handle(SequestreService $sequestre): int
    {
        $delai = config('afrishop.delai_confirmation_auto', 3);
        $this->info("Recherche des livraisons non contestées de plus de {$delai} jours…");

        $liberees = $sequestre->libererLesEchues();

        $this->info("{$liberees} sous-commande(s) libérée(s).");

        // Trace dans les logs : sert à prouver que la tâche tourne
        // réellement, et à diagnostiquer une chute soudaine.
        logger()->info('afrishop:liberer-fonds', ['liberees' => $liberees]);

        return self::SUCCESS;
    }
}
