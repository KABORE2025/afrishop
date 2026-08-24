<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un transporteur.
 *
 * `encaisse_especes` n'est pas un détail d'affichage : il décide si ce
 * transporteur peut être choisi pour une commande en paiement à la
 * livraison. Un livreur qui n'est pas habilité à collecter de l'argent
 * ne doit pas apparaître dans cette liste-là.
 */
class TransporteurResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'nom'              => $this->nom,
            'type'             => $this->type,
            'telephone'        => $this->telephone,
            'encaisse_especes' => (bool) $this->encaisse_especes,
        ];
    }
}
