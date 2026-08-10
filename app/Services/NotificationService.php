<?php

namespace App\Services;

use App\Models\GabaritNotification;
use App\Models\Notification;
use App\Models\Utilisateur;

/**
 * =====================================================================
 *  NOTIFICATIONS
 * =====================================================================
 *  Le poste de coût récurrent le plus sous-estimé d'une place de marché.
 *  Chaque SMS se paie, et un message de plus de 160 caractères en coûte
 *  deux. Sur dix mille commandes par mois avec cinq messages chacune,
 *  l'écart entre un gabarit bien écrit et un gabarit bavard se compte
 *  en centaines de milliers de francs par an.
 *
 *  D'où trois choix :
 *   · les textes vivent en base, par langue, et se modifient sans
 *     redéploiement ;
 *   · chaque envoi est journalisé avec son coût et son nombre de
 *     segments ;
 *   · l'envoi passe par une file d'attente : jamais une requête client
 *     bloquée sur une passerelle SMS lente.
 * =====================================================================
 */
class NotificationService
{
    /**
     * Met un message en file. Le rendu se fait ici, l'envoi plus tard :
     * on veut pouvoir prouver ce qui a été envoyé, pas ce qu'on aurait
     * voulu envoyer.
     */
    public function envoyer(string $code, string $canal, array $variables,
                            ?Utilisateur $destinataire = null, ?string $telephone = null,
                            string $langue = 'fr'): ?Notification
    {
        $gabarit = GabaritNotification::where('code', $code)->where('canal', $canal)
            ->where('langue', $langue)->where('actif', true)->first()
            // Repli sur le français : mieux vaut un message compris à
            // moitié qu'aucun message du tout.
            ?? GabaritNotification::where('code', $code)->where('canal', $canal)
                ->where('langue', 'fr')->where('actif', true)->first();

        if (! $gabarit) {
            logger()->warning('Gabarit de notification introuvable', compact('code', 'canal', 'langue'));
            return null;
        }

        $corps = $gabarit->corps;
        foreach ($variables as $cle => $valeur) {
            $corps = str_replace('{' . $cle . '}', (string) $valeur, $corps);
        }

        // Un SMS est facturé par tranche de 160 caractères (70 si le
        // message contient des caractères accentués encodés en UCS-2).
        $segments = $canal === 'sms' ? (int) max(1, ceil(mb_strlen($corps) / 160)) : 1;

        return Notification::create([
            'gabarit_id'      => $gabarit->id,
            'destinataire_id' => $destinataire?->id,
            'telephone'       => $telephone ?? $destinataire?->telephone,
            'email'           => $destinataire?->email,
            'canal'           => $canal,
            'corps_envoye'    => $corps,
            'statut'          => 'en_file',
            'nb_segments'     => $segments,
        ]);
    }

    /** Coût des notifications sur une période — à suivre chaque mois. */
    public function coutPeriode(\DateTimeInterface $debut, \DateTimeInterface $fin): array
    {
        return Notification::whereBetween('cree_le', [$debut, $fin])
            ->selectRaw('canal, COUNT(*) AS nb, SUM(nb_segments) AS segments, SUM(cout_cfa) AS cout_cfa')
            ->groupBy('canal')->get()->keyBy('canal')->toArray();
    }
}
