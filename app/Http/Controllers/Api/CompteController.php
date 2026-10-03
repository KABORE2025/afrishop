<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * =====================================================================
 *  MOT DE PASSE — changer, et retrouver par SMS
 * =====================================================================
 *  Les vendeurs, administrateurs et agents reçoivent un mot de passe
 *  TEMPORAIRE, montré une seule fois. Sans ces deux routes, ils ne
 *  pouvaient ni le remplacer, ni le récupérer s'ils l'oubliaient.
 *
 *  Mot de passe oublié : un code à 6 chiffres part par SMS au numéro du
 *  compte (jamais à un autre), valable 15 minutes, 5 essais. La réponse
 *  est la MÊME que le numéro existe ou non : sinon la page permettrait
 *  de savoir quels numéros ont un compte.
 *
 *  Changer ou réinitialiser le mot de passe ferme toutes les autres
 *  sessions : c'est le geste qu'on fait quand on craint qu'un tiers
 *  connaisse l'ancien.
 * =====================================================================
 */
class CompteController extends Controller
{
    private const VALIDITE_MINUTES = 15;
    private const ESSAIS_MAX = 5;

    public function changerMotDePasse(Request $r): JsonResponse
    {
        $donnees = $r->validate([
            'mot_de_passe_actuel' => ['required', 'string'],
            'mot_de_passe'        => ['required', 'confirmed', Password::min(8), 'different:mot_de_passe_actuel'],
        ], [
            'mot_de_passe.different' => 'Le nouveau mot de passe doit être différent de l’actuel.',
        ]);

        $utilisateur = $r->user();
        if (! Hash::check($donnees['mot_de_passe_actuel'], (string) $utilisateur->getAuthPassword())) {
            return response()->json([
                'message' => 'Mot de passe actuel incorrect.',
                'errors'  => ['mot_de_passe_actuel' => ['Mot de passe actuel incorrect.']],
            ], 422);
        }

        $utilisateur->update(['mot_de_passe' => $donnees['mot_de_passe']]);

        // Toutes les AUTRES sessions sont fermées ; celle-ci reste ouverte.
        $courant = $r->user()->currentAccessToken();
        $utilisateur->tokens()->when($courant?->id, fn ($q) => $q->where('id', '!=', $courant->id))->delete();

        return response()->json(['message' => 'Mot de passe modifié. Vos autres sessions ont été fermées.']);
    }

    public function demanderCode(Request $r): JsonResponse
    {
        $donnees = $r->validate(['telephone' => ['required', 'string', 'max:25']]);
        $utilisateur = $this->compte($donnees['telephone']);

        // Compte sans mot de passe (livreur pas encore activé) : il s'active
        // avec le code reçu à l'invitation, pas ici. Rien n'est envoyé.
        if ($utilisateur && $utilisateur->mot_de_passe !== null) {
            $code = (string) random_int(100000, 999999);
            Cache::put($this->cle($utilisateur), ['hash' => Hash::make($code), 'essais' => 0],
                now()->addMinutes(self::VALIDITE_MINUTES));

            Notification::create([
                'destinataire_id' => $utilisateur->id,
                'telephone'       => $utilisateur->telephone,
                'canal'           => 'sms',
                'corps_envoye'    => "Afrishop : votre code pour changer de mot de passe est {$code}. "
                    .'Valable '.self::VALIDITE_MINUTES.' minutes. Ne le donnez à personne.',
                'statut'          => 'en_file',
                'nb_segments'     => 1,
            ]);
        }

        return response()->json([
            'message' => 'Si ce numéro a un compte Afrishop, un code vient de lui être envoyé par SMS. '
                .'Il est valable '.self::VALIDITE_MINUTES.' minutes.',
        ]);
    }

    public function reinitialiser(Request $r): JsonResponse
    {
        $donnees = $r->validate([
            'telephone'    => ['required', 'string', 'max:25'],
            'code'         => ['required', 'digits:6'],
            'mot_de_passe' => ['required', 'confirmed', Password::min(8)],
        ]);

        $refus = response()->json(['message' => 'Code invalide ou expiré. Demandez-en un nouveau.'], 422);

        $utilisateur = $this->compte($donnees['telephone']);
        if (! $utilisateur) {
            return $refus;
        }

        $cle = $this->cle($utilisateur);
        $attente = Cache::get($cle);
        if (! $attente) {
            return $refus;
        }

        if (! Hash::check($donnees['code'], $attente['hash'])) {
            $attente['essais']++;
            // Au 5e essai faux, le code est détruit : un code à 6 chiffres
            // ne doit pas pouvoir être deviné par essais successifs.
            $attente['essais'] >= self::ESSAIS_MAX
                ? Cache::forget($cle)
                : Cache::put($cle, $attente, now()->addMinutes(self::VALIDITE_MINUTES));

            return $refus;
        }

        Cache::forget($cle);
        $utilisateur->update(['mot_de_passe' => $donnees['mot_de_passe']]);
        $utilisateur->tokens()->delete();

        return response()->json(['message' => 'Mot de passe changé. Vous pouvez vous connecter.', 'role' => $utilisateur->role]);
    }

    /** Le compte de ce numéro, avec ou sans indicatif. */
    private function compte(string $telephone): ?Utilisateur
    {
        $chiffres = preg_replace('/\D/', '', $telephone);
        if (strlen($chiffres) < 8) {
            return null;
        }

        return Utilisateur::where('telephone', $chiffres)
            ->orWhere('telephone', 'like', '%'.$chiffres)
            ->orderByRaw('telephone = ? desc', [$chiffres])
            ->first();
    }

    private function cle(Utilisateur $u): string
    {
        return 'reinit-mdp:'.$u->id;
    }
}
