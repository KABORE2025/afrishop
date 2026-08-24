<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une variante — ce qu'on met réellement au panier.
 *
 * `stock` est volontairement SIGNÉ en base : une survente constatée
 * doit rester visible en négatif plutôt que d'être masquée à zéro. On
 * la transmet telle quelle, et l'écran doit savoir afficher un stock
 * négatif comme une alerte, pas comme une rupture ordinaire.
 */
class VarianteProduitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'      => $this->id,
            'sku'     => $this->sku,
            'libelle' => $this->libelle,

            /* NULL veut dire « reprend le prix du produit ». On expose
             * les deux : la valeur propre et le prix effectif, pour que
             * l'écran d'édition sache distinguer « pas de prix propre »
             * de « prix propre égal à celui du produit ». */
            'prix_ttc_cfa'          => $this->prix_ttc_cfa !== null ? (int) $this->prix_ttc_cfa : null,
            'prix_effectif_ttc_cfa' => $this->prixTtc(),

            'stock'          => (int) $this->stock,
            'seuil_alerte'   => (int) $this->seuil_alerte,
            'en_rupture'     => $this->enRupture(),
            'stock_critique' => $this->stockCritique(),
            'defaut'         => (bool) $this->defaut,
            'actif'          => (bool) $this->actif,
        ];
    }
}
