<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 *  UN LITIGE, VU PAR L'ARBITRE
 * =====================================================================
 *  Cette Resource porte une exigence d'équité, pas seulement des champs.
 *
 *  `argument_boutique` est exposé au même niveau que la description du
 *  client, et non relégué dans un sous-objet. La réputation est devenue
 *  un actif pour un vendeur : arbitrer sans avoir lu sa version, c'est
 *  laisser un client de mauvaise foi abîmer durablement une boutique
 *  honnête. L'écran doit montrer les deux versions côte à côte, et la
 *  structure de cette réponse l'y oblige.
 *
 *  `montant_en_jeu_cfa` est là pour hiérarchiser : un litige à
 *  180 000 F et un à 2 500 F ne méritent pas la même minute d'attention.
 * =====================================================================
 */
class LitigeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'reference' => $this->reference,
            'motif'     => $this->motif,
            'statut'    => $this->statut,

            'client' => [
                'description' => $this->description,
                /* Liens signés, 30 min. Une liste vide est une information :
                 * l'écran dit « aucune photo fournie ». */
                'photos'      => $this->whenLoaded('photos',
                    fn () => app(\App\Services\LitigeService::class)->urlsPhotos($this->resource), []),
                'ouvert_le'   => $this->ouvert_le
                    ? \Illuminate\Support\Carbon::parse($this->ouvert_le)->toIso8601String()
                    : null,
            ],

            'boutique' => [
                'argument'   => $this->argument_boutique,
                'conteste_le'=> $this->conteste_par_boutique_le
                    ? \Illuminate\Support\Carbon::parse($this->conteste_par_boutique_le)->toIso8601String()
                    : null,
                /* Signalé explicitement : arbitrer sans avoir entendu la
                 * boutique est possible, mais l'écran doit le dire. */
                'a_repondu'  => $this->argument_boutique !== null,
            ],

            'resolution' => $this->resolution,
            'resolu_le'  => $this->resolu_le
                ? \Illuminate\Support\Carbon::parse($this->resolu_le)->toIso8601String()
                : null,

            'sous_commande' => $this->whenLoaded('sousCommande', fn () => [
                'id'          => $this->sousCommande->id,
                'reference'   => $this->sousCommande->reference,
                'statut'      => $this->sousCommande->statut,
                'etat_fonds'  => $this->sousCommande->etat_fonds?->value,
                'boutique_id' => $this->sousCommande->boutique_id,
                'montant_en_jeu_cfa' => (int) $this->sousCommande->montant_articles_ttc_cfa,
            ]),
        ];
    }
}
