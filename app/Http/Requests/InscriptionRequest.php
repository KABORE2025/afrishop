<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pays_id'      => ['required', 'integer', 'exists:pays,id'],
            'nom'          => ['required', 'string', 'max:120'],
            'telephone'    => ['required', 'string', 'max:20', 'unique:utilisateurs,telephone'],
            'mot_de_passe' => ['required', 'string', 'min:8'],
        ];
        // Le rôle n'est volontairement PAS un champ de ce formulaire :
        // il est forcé à « client » dans le contrôleur. L'accepter ici
        // permettrait à n'importe qui de s'auto-déclarer vendeur ou admin.
    }
}
