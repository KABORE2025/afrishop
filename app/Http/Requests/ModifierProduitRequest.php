<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Modification d'un produit existant.
 *
 * Tous les champs sont facultatifs : on envoie ce qu'on change. Mais
 * NOTER CE QUI N'EST PAS MODIFIABLE ICI, et volontairement :
 *
 *  · `reference` — imprimée sur des étiquettes et citée dans des
 *    commandes passées ; la changer casserait la traçabilité.
 *  · `slug` — l'URL de la fiche a pu être partagée ; la changer produit
 *    un lien mort.
 *  · `boutique_id` — un produit ne change pas de propriétaire.
 *  · `statut_moderation` — c'est Afrishop qui modère, pas le vendeur.
 *    Le laisser modifiable reviendrait à laisser chaque boutique
 *    publier ce qu'elle veut, ce qui est exactement ce que la
 *    modération existe pour empêcher.
 */
class ModifierProduitRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Le cloisonnement par boutique est vérifié dans le contrôleur.
        return $this->user()?->estVendeur() ?? false;
    }

    public function rules(): array
    {
        return [
            'nom'          => ['sometimes', 'string', 'max:160'],
            'description'  => ['sometimes', 'nullable', 'string', 'max:5000'],
            'categorie_id' => ['sometimes', 'integer', Rule::exists('categories', 'id')->where('active', true)],
            'prix_ttc_cfa' => ['sometimes', 'integer', 'min:1'],
            'poids_g'      => ['sometimes', 'nullable', 'integer', 'min:0'],
            'tracable'     => ['sometimes', 'boolean'],
            /* Dépublier son propre produit est légitime — une rupture
             * durable, un article retiré de la gamme. Le republier
             * l'est aussi : la modération, elle, ne repasse pas. */
            'actif'        => ['sometimes', 'boolean'],
        ];
    }
}
