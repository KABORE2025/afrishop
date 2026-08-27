<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un produit, vu par sa boutique.
 *
 * `moderation` porte une explication en clair, pas seulement un code.
 * Un vendeur qui crée une fiche et ne la voit pas apparaître en vitrine
 * appelle le support — sauf si l'interface lui a dit, au moment même,
 * que sa fiche attend une validation. Le texte est ici plutôt que dans
 * l'écran pour qu'il soit le même partout.
 */
class ProduitResource extends JsonResource
{
    private const EXPLICATIONS = [
        'brouillon'  => 'Brouillon — non soumis à la validation.',
        'en_attente' => 'En attente de validation par Afrishop. La fiche n\'est pas encore visible en vitrine.',
        'publie'     => 'Publiée et visible en vitrine.',
        'rejete'     => 'Refusée. Voir le motif, corriger la fiche et la soumettre à nouveau.',
        'retire'     => 'Retirée de la vitrine par Afrishop.',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'reference'    => $this->reference,
            'nom'          => $this->nom,
            'slug'         => $this->slug,
            'description'  => $this->description,
            'prix_ttc_cfa' => (int) $this->prix_ttc_cfa,
            'poids_g'      => $this->poids_g !== null ? (int) $this->poids_g : null,
            'actif'        => (bool) $this->actif,
            'tracable'     => (bool) $this->tracable,
            'categorie_id' => (int) $this->categorie_id,

            /*
             * La boutique et la catégorie ne sont rendues QUE si elles
             * ont été chargées. L'espace vendeur ne les charge pas — il
             * sait déjà de quelle boutique il s'agit — mais la console
             * de modération en a besoin : juger une fiche sans savoir
             * qui la propose ni dans quel rayon elle atterrit n'a pas
             * de sens.
             */
            'boutique' => $this->whenLoaded('boutique', fn () => [
                'id'    => $this->boutique->id,
                'nom'   => $this->boutique->nom,
                'code'  => $this->boutique->code,
                'emoji' => $this->boutique->emoji,
            ]),

            'categorie' => $this->whenLoaded('categorie', fn () => [
                'id'    => $this->categorie->id,
                'nom'   => $this->categorie->nom,
                'emoji' => $this->categorie->emoji,
            ]),

            'moderation' => [
                'statut'      => $this->statut_moderation,
                'explication' => self::EXPLICATIONS[$this->statut_moderation] ?? $this->statut_moderation,
                'motif'       => $this->motif_moderation,
                'date'        => $this->modere_le
                    ? \Illuminate\Support\Carbon::parse($this->modere_le)->toIso8601String()
                    : null,
            ],

            /* Les médias en premier dans l'esprit du vendeur comme de
             * l'acheteur : un produit sans photo ne se vend pas. */
            'medias'      => MediaResource::collection($this->whenLoaded('medias')),

            'stock_total' => $this->whenLoaded('variantes', fn () => (int) $this->variantes->where('actif', true)->sum('stock')),
            'variantes'   => VarianteProduitResource::collection($this->whenLoaded('variantes')),

            'cree_le'    => $this->cree_le?->toIso8601String(),
            'modifie_le' => $this->modifie_le?->toIso8601String(),
        ];
    }
}
