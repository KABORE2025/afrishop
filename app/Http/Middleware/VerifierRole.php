<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contrôle du rôle sur une route : ->middleware('role:admin')
 *
 * Volontairement minimal. Dès que les règles se compliqueront (un agent
 * peut trancher un litige mais pas modifier une commission), basculer
 * sur des Policies Laravel plutôt que d'empiler des rôles ici.
 */
class VerifierRole
{
    public function handle(Request $request, Closure $suivant, string ...$roles): Response
    {
        $utilisateur = $request->user();

        // Un administrateur passe partout : inutile de lister « admin »
        // sur chaque route protégée.
        if ($utilisateur && ($utilisateur->role === 'admin' || in_array($utilisateur->role, $roles, true))) {
            return $suivant($request);
        }

        abort(403, "Vous n'avez pas les droits nécessaires pour cette action.");
    }
}
