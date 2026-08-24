<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 *  UNE SOUS-COMMANDE, VUE PAR LA BOUTIQUE
 * =====================================================================
 *  POURQUOI CETTE CLASSE EXISTE
 *  Sans elle, les contrôleurs renvoyaient le modèle Eloquent brut :
 *  toutes les colonnes de la table partaient dans la réponse, et le
 *  front se retrouvait couplé au schéma de la base. Renommer une
 *  colonne aurait cassé l'interface.
 *
 *  LA DÉCISION DE CONCEPTION LA PLUS IMPORTANTE EST ICI
 *  `statut` et `etat_fonds` sont exposés comme DEUX blocs distincts,
 *  jamais fusionnés en un seul « statut ». Une commande livrée dont
 *  les fonds sont encore en séquestre est le cas NORMAL pendant toute
 *  la durée du séquestre. Un développeur front qui ne voit qu'un champ
 *  « statut » fusionnera les deux sans le savoir — la structure de
 *  cette réponse le lui interdit.
 * =====================================================================
 */
class SousCommandeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'reference' => $this->reference,

            /* AXE 1 — la marchandise. */
            'marchandise' => [
                'statut'      => $this->statut,
                'expedie_le'  => $this->expedie_le?->toIso8601String(),
                'livre_le'    => $this->livre_le?->toIso8601String(),
                'confirme_le' => $this->confirme_par_client_le?->toIso8601String(),
            ],

            /* AXE 2 — l'argent. Libellé fourni par l'enum : le front ne
             * réécrit pas ses propres traductions, sinon elles divergent. */
            'argent' => [
                'etat'    => $this->etat_fonds?->value,
                'libelle' => $this->etat_fonds?->libelle(),
            ],

            /*
             * Montants tous en francs CFA entiers. Aucun flottant nulle
             * part : un centime de franc n'existe pas, et un arrondi
             * d'affichage sur de l'argent finit toujours par produire un
             * écart que personne ne sait expliquer.
             */
            'montants_cfa' => [
                'articles_ttc'   => (int) $this->montant_articles_ttc_cfa,
                'tva'            => (int) $this->montant_tva_cfa,
                'frais_livraison'=> (int) $this->frais_livraison_cfa,
                'remise'         => (int) $this->remise_cfa,
                'commission'     => (int) $this->commission_cfa,
                /* PAS un revenu d'Afrishop : une dette envers le Trésor.
                 * Le libellé côté écran doit le dire explicitement. */
                'retenue_source' => (int) $this->retenue_source_cfa,
                'net_boutique'   => (int) $this->montant_net_cfa,
            ],

            /* Taux figés à la commande : renégocier n'affecte pas le passé. */
            'taux' => [
                'commission_pct'     => (float) $this->taux_commission_pct,
                'retenue_source_pct' => (float) $this->taux_retenue_source_pct,
            ],

            'commande' => $this->whenLoaded('commande', fn () => [
                'reference' => $this->commande->reference,
                'passee_le' => $this->commande->cree_le?->toIso8601String(),
            ]),

            'lignes'     => LigneCommandeResource::collection($this->whenLoaded('lignes')),
            'expedition' => new ExpeditionResource($this->whenLoaded('expedition')),

            'cree_le' => $this->cree_le?->toIso8601String(),
        ];
    }
}
