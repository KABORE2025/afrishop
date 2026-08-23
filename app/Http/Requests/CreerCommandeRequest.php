<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation de la création d'une commande.
 *
 * Le panier est vérifié plus finement dans PanierService::creerCommande()
 * (stock, boutique ouverte, plafond de paiement) : ici, on ne garde que
 * ce qui doit être rejeté AVANT de toucher à la base.
 */
class CreerCommandeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Commande possible en invité : l'essentiel du marché achète
        // sans compte. Si connecté, l'utilisateur est simplement rattaché.
        return true;
    }

    public function rules(): array
    {
        return [
            'pays_id'              => ['required', 'integer', 'exists:pays,id'],

            'articles'                    => ['required', 'array', 'min:1'],
            'articles.*.variante_id'      => ['required', 'integer', 'exists:variantes_produit,id'],
            'articles.*.quantite'         => ['required', 'integer', 'min:1', 'max:999'],

            'nom'                  => ['required', 'string', 'max:120'],
            'telephone'            => ['required', 'string', 'max:20'],
            'ville_id'             => ['nullable', 'integer', 'exists:villes,id'],
            'quartier'             => ['required', 'string', 'max:120'],
            'repere'               => ['nullable', 'string', 'max:255'],
            'mode_livraison'       => ['nullable', 'in:domicile,point_relais,retrait_boutique'],
            'point_relais_id'      => ['nullable', 'integer', 'exists:points_relais,id'],
            'mode_paiement'        => ['required', 'in:mobile_money,carte,virement,especes_livraison'],
            'cgv_version'          => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'articles.required'   => 'Le panier est vide.',
            'articles.*.variante_id.exists' => "L'un des articles du panier n'existe plus.",
        ];
    }
}
