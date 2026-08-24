<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une ligne de commande.
 *
 * Tous les champs viennent de la ligne elle-même, jamais du catalogue :
 * le nom, le prix et le taux de TVA ont été RECOPIÉS au moment de la
 * vente. Rejoindre le produit pour « avoir le vrai nom » ferait changer
 * une facture d'hier le jour où le vendeur renomme son article — c'est
 * précisément ce que la dénormalisation du modèle interdit.
 */
class LigneCommandeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'sku'              => $this->sku,
            'nom_produit'      => $this->nom_produit,
            'libelle_variante' => $this->libelle_variante,
            'quantite'         => (int) $this->quantite,
            'prix_unitaire_ttc_cfa' => (int) $this->prix_unitaire_ttc_cfa,
            'total_ttc_cfa'    => (int) $this->total_ttc_cfa,
            'total_tva_cfa'    => (int) $this->total_tva_cfa,
            'taux_tva_pct'     => (float) $this->taux_tva_pct,
        ];
    }
}
