<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validation de la création d'un lot d'étiquettes.
 *
 * Les messages sont rédigés en français courant, pas en jargon : ils
 * s'affichent tels quels dans la console d'administration, utilisée par
 * des gens qui ne sont pas informaticiens.
 */
class CreerLotQrRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Seul un administrateur Afrishop génère des lots. Un vendeur
        // peut en DEMANDER un (statut « demande »), ce qui passe par une
        // autre route : autoriser un vendeur à générer ses propres codes
        // reviendrait à lui permettre de fabriquer ses propres preuves
        // d'authenticité.
        return $this->user()?->estAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'produit_id'       => ['required', 'integer', 'exists:produits,id'],
            'date_fabrication' => ['required', 'date', 'before_or_equal:today'],
            'date_expiration'  => ['required', 'date', 'after:date_fabrication'],
            'fabricant'        => ['required', 'string', 'max:160'],
            'numero_debut'     => ['nullable', 'integer', 'min:0'],
            'quantite'         => ['required', 'integer', 'min:1',
                                   'max:' . config('afrishop.qr.quantite_max_par_lot', 10000)],
            'largeur_numero'   => ['nullable', 'integer', 'min:3', 'max:8'],
            'description'      => ['nullable', 'string', 'max:1000'],
            'demande_par_id'   => ['nullable', 'integer', 'exists:utilisateurs,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'date_fabrication.before_or_equal' => "La date de fabrication ne peut pas être dans le futur.",
            'date_expiration.after'            => "La date d'expiration doit être postérieure à la date de fabrication. Vérifiez que vous n'avez pas inversé les deux champs.",
            'quantite.max'                     => "Vous ne pouvez pas générer plus de :max étiquettes en une fois. Créez plusieurs lots.",
        ];
    }

    /**
     * Contrôle supplémentaire : prévenir le chevauchement de numérotation.
     *
     * Si l'utilisateur force un numéro de départ qui recouvre un lot
     * existant du même produit, on refuse : deux étiquettes physiques
     * porteraient le même code lisible et l'inventaire deviendrait
     * ininterprétable.
     */
    public function after(): array
    {
        return [
            function (Validator $validateur) {
                $debut = $this->input('numero_debut');
                if ($debut === null) {
                    return;  // numérotation automatique : pas de risque
                }

                $fin = $debut + $this->input('quantite') - 1;

                $chevauche = \App\Models\LotQr::where('produit_id', $this->input('produit_id'))
                    ->whereRaw('? <= numero_debut + quantite - 1 AND ? >= numero_debut', [$debut, $fin])
                    ->exists();

                if ($chevauche) {
                    $validateur->errors()->add(
                        'numero_debut',
                        "Cette plage de numéros ({$debut} à {$fin}) recouvre un lot déjà généré pour ce produit. " .
                        "Laissez le champ vide pour reprendre automatiquement la numérotation."
                    );
                }
            },
        ];
    }
}
