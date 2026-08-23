<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreerProduitRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Le cloisonnement par boutique est vérifié dans le contrôleur,
        // après résolution de la boutique du vendeur connecté.
        return $this->user()?->estVendeur() ?? false;
    }

    public function rules(): array
    {
        return [
            'nom'          => ['required', 'string', 'max:160'],
            'description'  => ['nullable', 'string', 'max:5000'],
            'categorie_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('active', true)],
            'prix_ttc_cfa' => ['required', 'integer', 'min:1'],
            'poids_g'      => ['nullable', 'integer', 'min:0'],
            'tracable'     => ['nullable', 'boolean'],

            'variantes'                    => ['nullable', 'array', 'min:1'],
            'variantes.*.libelle'          => ['required_with:variantes', 'string', 'max:120'],
            'variantes.*.prix_ttc_cfa'     => ['nullable', 'integer', 'min:1'],
            'variantes.*.stock'            => ['required_with:variantes', 'integer', 'min:0'],
            'variantes.*.seuil_alerte'     => ['nullable', 'integer', 'min:0'],
        ];
    }
}
