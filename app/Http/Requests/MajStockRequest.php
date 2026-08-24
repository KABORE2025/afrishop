<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mise à jour d'une variante — le geste le plus fréquent d'une boutique.
 *
 * DEUX FAÇONS DE TOUCHER AU STOCK, ET ELLES NE SE VALENT PAS :
 *
 *  · `stock` — valeur absolue. « Après inventaire, il me reste 12. »
 *  · `mouvement` — variation relative. « J'en ai reçu 20 de plus. »
 *
 * La seconde est la bonne quand plusieurs personnes tiennent la même
 * boutique : deux réceptions saisies en même temps s'additionnent, alors
 * que deux valeurs absolues s'écrasent et l'une des deux est perdue.
 * Les deux sont acceptées, jamais ensemble.
 */
class MajStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->estVendeur() ?? false;
    }

    public function rules(): array
    {
        return [
            'stock'        => ['sometimes', 'integer', 'min:0'],
            'mouvement'    => ['sometimes', 'integer'],
            'libelle'      => ['sometimes', 'string', 'max:120'],
            'prix_ttc_cfa' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'seuil_alerte' => ['sometimes', 'integer', 'min:0'],
            'actif'        => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validateur): void
    {
        $validateur->after(function ($v) {
            if ($this->has('stock') && $this->has('mouvement')) {
                $v->errors()->add('stock',
                    'Envoyez « stock » (valeur après inventaire) OU « mouvement » (variation), pas les deux.');
            }
        });
    }
}
