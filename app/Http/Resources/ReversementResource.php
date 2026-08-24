<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 *  UN REVERSEMENT
 * =====================================================================
 *  LES CINQ MONTANTS SONT LUS EN BASE, JAMAIS RECALCULÉS.
 *
 *  brut − commission − retenue − frais de transfert = net. La tentation
 *  est de ne transmettre que le brut et de refaire la soustraction à
 *  l'affichage : c'est exactement ce qu'il ne faut pas faire. Les taux
 *  changent, les barèmes évoluent, et un recalcul avec les valeurs
 *  d'aujourd'hui donnerait un montant différent de celui réellement
 *  viré il y a trois mois. Un vendeur qui voit deux chiffres différents
 *  pour le même virement ne fait plus confiance à la plateforme.
 *
 *  `retenue_source_cfa` est prélevée POUR LE COMPTE DE L'ÉTAT. L'écran
 *  doit le dire : présentée comme une ponction Afrishop, elle passe
 *  pour une commission déguisée de 25 %.
 * =====================================================================
 */
class ReversementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'reference' => $this->reference,

            'periode' => [
                'debut' => $this->periode_debut,
                'fin'   => $this->periode_fin,
            ],

            'montants_cfa' => [
                'brut'            => (int) $this->montant_brut_cfa,
                'commission'      => (int) $this->commission_cfa,
                'retenue_source'  => (int) $this->retenue_source_cfa,
                'frais_transfert' => (int) $this->frais_transfert_cfa,
                'net'             => (int) $this->montant_net_cfa,
            ],

            /* Aide à la lecture, pour que l'écran n'invente pas ses
             * propres formulations sur un sujet aussi sensible. */
            'note_retenue' => $this->retenue_source_cfa > 0
                ? 'Montant prélevé pour le compte de l\'administration fiscale, et non par Afrishop.'
                : null,

            'statut' => $this->statut,

            /* Un virement échoué laisse les fonds en séquestre : ils ne
             * sont pas perdus. Le dire, sinon le vendeur croit à un vol. */
            'motif_echec'      => $this->motif_echec,
            'motif_suspension' => $this->motif_suspension,

            'execute_le' => $this->execute_le
                ? \Illuminate\Support\Carbon::parse($this->execute_le)->toIso8601String()
                : null,
            'cree_le' => $this->cree_le
                ? \Illuminate\Support\Carbon::parse($this->cree_le)->toIso8601String()
                : null,
        ];
    }
}
