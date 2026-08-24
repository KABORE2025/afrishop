<?php

namespace App\Services\Sms;

use App\Models\Notification;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 *  DRAINAGE DE LA FILE DE NOTIFICATIONS
 * =====================================================================
 *  NotificationService MET EN FILE (`statut = en_file`). Cette classe
 *  ENVOIE. La séparation était déjà prévue dans le modèle — la table
 *  `notifications` porte `fournisseur`, `reference_externe`, `cout_cfa`,
 *  `erreur` et `envoye_le` — mais personne n'avait écrit l'envoi.
 *
 *  POURQUOI UNE FILE PLUTÔT QU'UN ENVOI DIRECT
 *  Trois raisons, dans l'ordre d'importance :
 *   1. Une passerelle SMS lente ne doit jamais bloquer un client en
 *      train de valider sa commande.
 *   2. Orange plafonne à 5 SMS par seconde. Une commande multi-boutiques
 *      qui notifie quatre vendeurs d'un coup dépasserait ce plafond.
 *   3. Un échec doit être visible et rejouable. Un envoi direct qui
 *      échoue disparaît ; une ligne en file reste.
 * =====================================================================
 */
class EnvoiSmsService
{
    public function __construct(private PasserelleSmsInterface $passerelle) {}

    /**
     * Envoie les notifications SMS en attente.
     *
     * @param  int $limite Nombre maximum de messages par passage.
     * @return array{traitees: int, envoyees: int, echouees: int, cout_cfa: int}
     */
    public function drainer(int $limite = 100): array
    {
        $enFile = Notification::where('canal', 'sms')
            ->where('statut', 'en_file')
            ->whereNotNull('telephone')
            ->orderBy('cree_le')
            ->limit($limite)
            ->get();

        $bilan = ['traitees' => 0, 'envoyees' => 0, 'echouees' => 0, 'cout_cfa' => 0];

        foreach ($enFile as $notification) {
            // Marquée AVANT l'appel réseau. Si le processus meurt en
            // plein envoi, la notification reste « en_file » et sera
            // rejouée — un doublon vaut mieux qu'un code de livraison
            // jamais reçu. Le verrou empêche deux exécutions
            // simultanées de prendre la même ligne.
            $prise = DB::transaction(function () use ($notification) {
                $fraiche = Notification::whereKey($notification->id)
                    ->lockForUpdate()->first();

                if (! $fraiche || $fraiche->statut !== 'en_file') {
                    return null;   // déjà prise par un autre passage
                }

                return $fraiche;
            });

            if ($prise === null) {
                continue;
            }

            $bilan['traitees']++;

            $resultat = $this->passerelle->envoyer($prise->telephone, $prise->corps_envoye);

            $cout = $resultat['statut'] === 'envoye'
                ? $this->passerelle->coutUnitaireCfa() * max(1, (int) $prise->nb_segments)
                : 0;

            $prise->update([
                'statut'            => $resultat['statut'],
                'fournisseur'       => $this->passerelle->nom(),
                'reference_externe' => $resultat['reference_externe'],
                'erreur'            => $resultat['erreur'],
                'cout_cfa'          => $cout,
                'envoye_le'         => $resultat['statut'] === 'envoye' ? now() : null,
            ]);

            $resultat['statut'] === 'envoye' ? $bilan['envoyees']++ : $bilan['echouees']++;
            $bilan['cout_cfa'] += $cout;

            // Respect du plafond de 5 envois par seconde imposé par
            // Orange. 220 ms laisse une marge : dépasser le plafond
            // fait rejeter les messages, pas ralentir l'API.
            usleep(220_000);
        }

        return $bilan;
    }
}
