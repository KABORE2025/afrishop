<?php

namespace App\Console\Commands;

use App\Models\TransactionPaiement;
use App\Services\Paiement\PaiementCommandeService;
use App\Services\Paiement\PaymentGatewayInterface;
use Illuminate\Console\Command;

/**
 * =====================================================================
 *  RATTRAPAGE DES PAIEMENTS SANS NOTIFICATION
 * =====================================================================
 *  LE PROBLÈME QU'ELLE RÉSOUT, ET IL N'EST PAS THÉORIQUE.
 *
 *  Le webhook est le chemin normal, mais il n'arrive pas toujours :
 *  coupure réseau au mauvais moment, pare-feu qui bloque le port
 *  entrant, serveur redémarré pendant l'appel, tunnel de développement
 *  fermé. La notification est perdue, et la commande reste « en
 *  attente » POUR TOUJOURS.
 *
 *  C'est la panne la plus vicieuse de tout le circuit, parce que
 *  personne ne la voit : le client a vu son débit et attend son colis ;
 *  le vendeur n'a jamais vu passer la commande ; l'argent dort chez le
 *  prestataire. Aucune alerte, aucune erreur, aucun journal.
 *
 *  Cette commande va CHERCHER l'information au lieu de l'attendre. Elle
 *  reprend chaque transaction encore en attente, demande son état réel
 *  au prestataire, et la finalise par les mêmes méthodes que le webhook
 *  — donc avec la même garantie d'idempotence : une transaction déjà
 *  finalisée n'est jamais recréditée.
 *
 *  EN DÉVELOPPEMENT, elle remplace complètement le webhook. Derrière un
 *  réseau qui bloque les tunnels (ports 7844 ou 443 sortants filtrés),
 *  c'est le seul moyen de dérouler un paiement de bout en bout : on
 *  paie sur la page du prestataire, on lance cette commande, la
 *  commande passe à « encaisse ».
 *
 *  UTILISATION :
 *    php artisan afrishop:verifier-paiements
 *    php artisan afrishop:verifier-paiements --reference=AFR-2026-000123
 *    php artisan afrishop:verifier-paiements --age=0     (sans délai d'attente)
 * =====================================================================
 */
class VerifierPaiements extends Command
{
    protected $signature = 'afrishop:verifier-paiements
                            {--reference= : Ne vérifier que cette commande (sa référence)}
                            {--age=2 : Ne reprendre que les transactions initiées il y a plus de N minutes}
                            {--limite=100 : Nombre maximum de transactions par passage}';

    protected $description = "Redemande au prestataire l'état des paiements restés en attente";

    public function handle(
        PaymentGatewayInterface $passerelle,
        PaiementCommandeService $paiement,
    ): int {
        $requete = TransactionPaiement::query()
            ->where('sens', 'encaissement')
            ->whereIn('statut', ['initiee', 'en_attente'])
            ->whereNotNull('reference_externe');

        /*
         * DÉLAI D'ATTENTE AVANT REPRISE.
         *
         * Une transaction créée il y a dix secondes est normale : le
         * client est en train de saisir son code sur son téléphone.
         * L'interroger tout de suite ne donnerait que « pending », et
         * ferait un appel API pour rien à chaque passage. Deux minutes
         * laissent le temps au parcours normal — webhook compris — de
         * se dérouler.
         */
        if (($age = (int) $this->option('age')) > 0) {
            $requete->where('initiee_le', '<=', now()->subMinutes($age));
        }

        if ($reference = $this->option('reference')) {
            $requete->whereHas('commande', fn ($q) => $q->where('reference', $reference));
        }

        $transactions = $requete->orderBy('initiee_le')
            ->limit((int) $this->option('limite'))
            ->get();

        if ($transactions->isEmpty()) {
            $this->info('Aucun paiement en attente à vérifier.');

            return self::SUCCESS;
        }

        $this->info($transactions->count() . ' paiement(s) en attente.');

        $encaisses = $indetermines = $echoues = 0;

        foreach ($transactions as $tx) {
            $etat = $passerelle->verifierPaiement($tx->reference_externe);

            /*
             * « Indéterminé » N'EST PAS un échec. Le prestataire répond
             * « pending », ou son API est injoignable. Conclure à
             * l'échec annulerait la commande et remettrait le stock
             * alors que le client est peut-être en train de valider.
             * On ne décide rien et on redemandera au prochain passage.
             */
            if ($etat === null) {
                $indetermines++;
                $this->line(sprintf('  <fg=yellow>?</> %-28s indéterminé', $tx->reference_externe));
                continue;
            }

            if ($etat['accepte']) {
                $paiement->finaliserReussie($tx);
                $encaisses++;
                $this->line(sprintf('  <fg=green>✓</> %-28s encaissé', $tx->reference_externe));
            } else {
                $paiement->finaliserEchouee($tx, $etat['message']);
                $echoues++;
                $this->line(sprintf('  <fg=red>✗</> %-28s %s', $tx->reference_externe, $etat['message']));
            }
        }

        $this->newLine();
        $this->info("Encaissés : {$encaisses}   Échoués : {$echoues}   Encore indéterminés : {$indetermines}");

        return self::SUCCESS;
    }
}
