<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Candidature d'une boutique — accessible sans compte. */
class CandidaterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pays_id'      => ['required', 'integer', 'exists:pays,id'],
            'nom_boutique' => ['required', 'string', 'max:120'],
            'responsable'  => ['required', 'string', 'max:120'],
            'telephone'    => ['required', 'string', 'max:20'],
            'ville_id'     => ['nullable', 'integer', 'exists:villes,id'],
            'categorie_id' => ['nullable', 'integer', 'exists:categories,id'],
            'description'  => ['required', 'string', 'max:2000'],
        ];
    }
}
