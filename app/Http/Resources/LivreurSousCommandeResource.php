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
            'montant_a_encaisser_cfa' => $this->commande->mode_paiement === 'especes_livraison'
                ? (int) ($this->montant_articles_ttc_cfa + $this->frais_livraison_cfa) : null,
            'client' => [
                'nom' => $this->commande->client_nom,
                'telephone' => $this->commande->client_telephone,
                'quartier' => $this->commande->quartier,
                'repere' => $this->commande->repere,
            ],
            'expedition' => [
                'code_suivi' => $this->expedition?->code_suivi,
                'expedie_le' => $this->expedition?->expedie_le?->toIso8601String(),
            ],
        ];
    }
}
