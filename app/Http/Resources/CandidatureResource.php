<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une candidature de boutique, vue par l'administration.
 *
 * `anciennete_jours` est calculée ici plutôt qu'affichée comme une date :
 * ce qui compte pour un opérateur, ce n'est pas le 3 août, c'est
 * « en attente depuis 18 jours ». Une candidature oubliée est un vendeur
 * qui part chez le concurrent, et personne ne remarque une date.
 */
class CandidatureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $creeLe = $this->cree_le ? \Illuminate\Support\Carbon::parse($this->cree_le) : null;

        return [
            'id'           => $this->id,
            'nom_boutique' => $this->nom_boutique,
            'responsable'  => $this->responsable,
            'telephone'    => $this->telephone,
            'description'  => $this->description,
            'pays_id'      => $this->pays_id,
            'ville_id'     => $this->ville_id,
            'categorie_id' => $this->categorie_id,

            'statut'      => $this->statut,
            'motif_refus' => $this->motif_refus,
            'boutique_id' => $this->boutique_id,

            'cree_le'          => $creeLe?->toIso8601String(),
            'anciennete_jours' => $creeLe?->diffInDays(now()),
            'traite_le'        => $this->traite_le
                ? \Illuminate\Support\Carbon::parse($this->traite_le)->toIso8601String()
                : null,
        ];
    }
}
