<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\Media;
use App\Models\OperateurPaiement;
use App\Models\Ville;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 *  MA BOUTIQUE — la fiche que le vendeur tient lui-même
 * =====================================================================
 *  Ce que le vendeur PEUT changer seul : description, téléphone, ville,
 *  logo, fermeture temporaire, compte de paiement.
 *
 *  Ce qu'il ne peut PAS changer seul : le nom (validé par Afrishop à la
 *  candidature, il est la marque que voient les clients), la commission,
 *  le statut, le niveau. Cela passe par le service client.
 *
 *  LE COMPTE DE PAIEMENT EST LE POINT SENSIBLE. Changer le numéro remet
 *  la vérification à zéro : sans cela, quiconque prend la main sur le
 *  compte d'un vendeur (téléphone volé, mot de passe deviné) détourne
 *  tous ses versements vers son propre numéro en un formulaire. Tant que
 *  le nouveau numéro n'est pas vérifié par Afrishop, la boutique ne
 *  vend plus et rien ne lui est versé (Boutique::peutVendre()).
 * =====================================================================
 */
class MaBoutiqueController extends Controller
{
    public function voir(Request $r): JsonResponse
    {
        $b = $this->boutique($r)->load(['ville:id,nom', 'operateurPaiement:id,nom', 'utilisateur:id,kyc_niveau']);

        return response()->json([
            'boutique' => [
                'id'          => $b->id,
                'code'        => $b->code,
                'nom'         => $b->nom,
                'slug'        => $b->slug,
                'statut'      => $b->statut,
                'description' => $b->description,
                'telephone'   => $b->telephone,
                'ville_id'    => $b->ville_id,
                'ville'       => $b->ville?->nom,
                'logo_url'    => $this->urlLogo($b),
                'fermee_du'   => $b->fermee_du?->toDateString(),
                'fermee_au'   => $b->fermee_au?->toDateString(),
                'message_fermeture' => $b->message_fermeture,
                'peut_vendre' => $b->peutVendre(),
                'raison_indisponibilite' => $b->raisonIndisponibilite(),
            ],
            'paiement' => [
                'operateur_paiement_id' => $b->operateur_paiement_id,
                'operateur'   => $b->operateurPaiement?->nom,
                'numero'      => $b->paiement_numero,
                'titulaire'   => $b->paiement_titulaire,
                'verifie_le'  => $b->paiement_verifie_le?->toIso8601String(),
            ],
            'identification' => $b->utilisateur?->kyc_niveau ?? 'aucun',
            'villes' => Ville::where('pays_id', $b->pays_id)->where('active', true)->orderBy('nom')->get(['id', 'nom']),
            'operateurs' => OperateurPaiement::where('pays_id', $b->pays_id)->where('actif', true)
                ->where('type', 'mobile_money')->orderBy('ordre')->get(['id', 'nom']),
        ]);
    }

    public function modifier(Request $r): JsonResponse
    {
        $b = $this->boutique($r);

        $donnees = $r->validate([
            'description' => ['nullable', 'string', 'max:2000'],
            'telephone'   => ['required', 'string', 'regex:/^\+?[0-9 ]{8,20}$/'],
            'ville_id'    => ['required', 'integer', Rule::exists('villes', 'id')->where('pays_id', $b->pays_id)->where('active', true)],
            'fermee_du'   => ['nullable', 'date'],
            'fermee_au'   => ['nullable', 'date', 'after_or_equal:fermee_du'],
            'message_fermeture' => ['nullable', 'string', 'max:255'],
        ], [
            'telephone.regex'    => 'Téléphone invalide : chiffres uniquement.',
            'ville_id.exists'    => 'Choisissez une ville de votre pays dans la liste.',
            'fermee_au.after_or_equal' => 'La date de réouverture doit suivre la date de fermeture.',
        ]);

        $b->update([
            'description' => $donnees['description'] ?? null,
            'telephone'   => preg_replace('/\D/', '', $donnees['telephone']),
            'ville_id'    => $donnees['ville_id'],
            'fermee_du'   => $donnees['fermee_du'] ?? null,
            'fermee_au'   => $donnees['fermee_du'] ? ($donnees['fermee_au'] ?? null) : null,
            'message_fermeture' => $donnees['fermee_du'] ? ($donnees['message_fermeture'] ?? null) : null,
        ]);

        return response()->json(['message' => 'Fiche de la boutique enregistrée.']);
    }

    public function modifierPaiement(Request $r): JsonResponse
    {
        $b = $this->boutique($r);

        $donnees = $r->validate([
            'operateur_paiement_id' => ['required', 'integer',
                Rule::exists('operateurs_paiement', 'id')->where('pays_id', $b->pays_id)->where('actif', true)],
            'numero'    => ['required', 'string', 'regex:/^\+?[0-9 ]{8,20}$/'],
            'titulaire' => ['required', 'string', 'min:3', 'max:120'],
        ], [
            'numero.regex' => 'Numéro Mobile Money invalide : chiffres uniquement.',
            'operateur_paiement_id.exists' => 'Choisissez un opérateur de votre pays.',
        ]);

        $numero = preg_replace('/\D/', '', $donnees['numero']);
        $inchange = (int) $b->operateur_paiement_id === (int) $donnees['operateur_paiement_id']
            && $b->paiement_numero === $numero && $b->paiement_titulaire === $donnees['titulaire'];

        if ($inchange) {
            return response()->json(['message' => 'Aucun changement.']);
        }

        $b->update([
            'operateur_paiement_id' => $donnees['operateur_paiement_id'],
            'paiement_numero'       => $numero,
            'paiement_titulaire'    => $donnees['titulaire'],
            // Nouvelle vérification obligatoire : voir l'en-tête du fichier.
            'paiement_verifie_le'   => null,
        ]);

        return response()->json([
            'message' => 'Compte de paiement enregistré. Afrishop doit le vérifier (petit virement de test) '
                .'avant que votre boutique puisse vendre et être payée.',
        ]);
    }

    public function logo(Request $r, MediaService $medias): JsonResponse
    {
        $b = $this->boutique($r);
        $r->validate(['logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096']], [
            'logo.max' => 'Logo trop lourd : 4 Mo au plus.',
        ]);

        $ancien = $b->logo_media_id ? Media::find($b->logo_media_id) : null;
        $media = $medias->ajouterImage($r->file('logo'), 'boutique', $b->id, $b->nom);
        $b->update(['logo_media_id' => $media->id]);

        if ($ancien) {
            $medias->supprimer($ancien);
        }

        return response()->json(['message' => 'Logo mis à jour.', 'logo_url' => $this->urlLogo($b->fresh())]);
    }

    private function boutique(Request $r): Boutique
    {
        $b = $r->user()?->boutique;
        abort_unless($b, 403, 'Aucune boutique rattachée à ce compte.');

        return $b;
    }

    private function urlLogo(Boutique $b): ?string
    {
        $m = $b->logo_media_id ? Media::find($b->logo_media_id) : null;
        $chemin = $m?->chemin_vignette ?? $m?->chemin_original;

        return $chemin ? Storage::disk('public')->url($chemin) : null;
    }
}
