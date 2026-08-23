<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConnexionRequest;
use App\Http\Requests\InscriptionRequest;
use App\Models\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * =====================================================================
 *  AUTHENTIFICATION — jetons Sanctum
 * =====================================================================
 *  L'identifiant de connexion est le TÉLÉPHONE, pas l'e-mail (voir
 *  App\Models\Utilisateur). Un compte créé ici est toujours un CLIENT :
 *  devenir vendeur passe par une candidature (CommandeController::
 *  candidater()) traitée par un administrateur, jamais par
 *  l'auto-inscription — un formulaire public ne doit jamais pouvoir
 *  s'attribuer un rôle.
 * =====================================================================
 */
class AuthController extends Controller
{
    public function inscrire(InscriptionRequest $r): JsonResponse
    {
        $utilisateur = Utilisateur::create([
            'pays_id'      => $r->validated('pays_id'),
            'nom'          => $r->validated('nom'),
            'telephone'    => $r->validated('telephone'),
            'mot_de_passe' => $r->validated('mot_de_passe'),
            'role'         => 'client',
        ]);

        return response()->json([
            'utilisateur' => $utilisateur,
            'jeton'       => $utilisateur->createToken('api')->plainTextToken,
        ], 201);
    }

    public function connecter(ConnexionRequest $r): JsonResponse
    {
        $utilisateur = Utilisateur::where('telephone', $r->validated('telephone'))->first();

        // Même message dans les deux cas : distinguer « téléphone
        // inconnu » de « mot de passe faux » permettrait d'énumérer les
        // numéros inscrits.
        if (! $utilisateur || $utilisateur->mot_de_passe === null
            || ! Hash::check($r->validated('mot_de_passe'), $utilisateur->getAuthPassword())) {
            return response()->json(['message' => 'Identifiants invalides.'], 422);
        }

        $utilisateur->update(['derniere_connexion' => now()]);

        return response()->json([
            'utilisateur' => $utilisateur,
            'jeton'       => $utilisateur->createToken('api')->plainTextToken,
        ]);
    }

    public function deconnecter(Request $r): JsonResponse
    {
        $r->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté.']);
    }

    public function moi(Request $r): JsonResponse
    {
        return response()->json($r->user()->load('boutique'));
    }
}
