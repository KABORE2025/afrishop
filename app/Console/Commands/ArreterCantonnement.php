<?php

namespace App\Console\Commands;

use App\Models\Pays;
use App\Services\CantonnementService;
use Illuminate\Console\Command;

/**
 * Arrête le cantonnement du jour pour un pays.
 *
 * PAS planifiée automatiquement (voir routes/console.php) : le solde du
 * compte de cantonnement est un chiffre humain — relevé bancaire ou,
 * plus tard, appel à une API bancaire non branchée aujourd'hui. Cette
 * commande existe pour qu'un opérateur (ou ce futur connecteur) puisse
 * fournir ce chiffre et déclencher l'arrêté.
 *
 * Exemple : php artisan afrishop:arreter-cantonnement BF 45230000
 */
class ArreterCantonnement extends Command
{
    protected $signature = 'afrishop:arreter-cantonnement {pays_code} {solde_banque_cfa}';
    protected $description = "Arrête le cantonnement du jour pour un pays, à partir du solde bancaire fourni";

    public function handle(CantonnementService $cantonnement): int
    {
        $pays = Pays::where('code_iso2', strtoupper($this->argument('pays_code')))->first();

        if (! $pays) {
            $this->error("Pays inconnu : « {$this->argument('pays_code')} ».");

            return self::FAILURE;
        }

        $arrete = $cantonnement->arreter($pays, now(), (int) $this->argument('solde_banque_cfa'));

        if ($arrete->statut === 'conforme') {
            $this->info("Cantonnement {$pays->code_iso2} conforme.");
        } else {
            $this->warn("ÉCART DE {$arrete->ecart_cfa} FCFA sur le cantonnement {$pays->code_iso2} — à expliquer avant la fin de journée.");
        }

        logger()->info('afrishop:arreter-cantonnement', [
            'pays' => $pays->code_iso2, 'statut' => $arrete->statut, 'ecart_cfa' => $arrete->ecart_cfa,
        ]);

        return self::SUCCESS;
    }
}
