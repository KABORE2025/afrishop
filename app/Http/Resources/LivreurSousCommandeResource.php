<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Données minimales pour remettre le bon colis au bon client. */
class LivreurSousCommandeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'client' => [
                'nom' => $this->commande->client_nom,
                'telephone' => $this->commande->client_telephone,
                'quartier' => $this->commande->quartier,
                'repere' => $this->commande->repere,
            ],
            'expedition' => [
                'statut'     => $this->expedition?->statut,
                'code_suivi' => $this->expedition?->code_suivi,
                'expedie_le' => $this->expedition?->expedie_le?->toIso8601String(),
                'livre_le'   => $this->expedition?->livre_le?->toIso8601String(),
            ],
            /* Compté depuis le journal, pas une colonne dédiée — voir
             * LivreurController::signalerEchec(). */
            'echecs_signales' => $this->evenements()->where('type', 'livraison_echouee')->count(),
        ];
    }
}
