<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Un média produit.
 *
 * Les URL sont construites ici et pas côté écran : le jour où les
 * fichiers partiront sur un stockage distant, seule cette classe
 * changera.
 *
 * `poids_ko` est exposé délibérément. Sur un réseau facturé au
 * mégaoctet, un acheteur a le droit de savoir ce qu'il s'apprête à
 * télécharger avant de le télécharger — c'est particulièrement vrai
 * pour la vidéo.
 */
class MediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'   => $this->id,
            'type' => $this->type ?? 'image',

            'urls' => [
                /* Pour une vidéo, `original` est le fichier lui-même :
                 * il ne doit JAMAIS être chargé sans un clic. */
                'original'  => $this->url($this->chemin_original),
                'grand'     => $this->url($this->chemin_grand ?? $this->chemin_original),
                'vignette'  => $this->url($this->chemin_vignette ?? $this->chemin_original),
                'miniature' => $this->url($this->chemin_miniature ?? $this->chemin_original),
                'poster'    => $this->url($this->chemin_poster),
            ],

            'mime'             => $this->mime,
            'largeur'          => $this->largeur,
            'hauteur'          => $this->hauteur,
            'poids_ko'         => $this->poids_octets ? (int) round($this->poids_octets / 1024) : null,
            'duree_s'          => $this->duree_s,
            'texte_alternatif' => $this->texte_alternatif,
            'ordre'            => (int) $this->ordre,
        ];
    }

    private function url(?string $chemin): ?string
    {
        return $chemin ? Storage::disk('public')->url($chemin) : null;
    }
}
