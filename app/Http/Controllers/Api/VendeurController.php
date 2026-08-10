<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SousCommande;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * =====================================================================
 *  ESPACE VENDEUR — AMORCE
 * =====================================================================
 *  Toutes ces routes passent par `auth:sanctum` et le middleware `role`
 *  déclarés dans routes/api.php.
 *
 *  LA RÈGLE QUI PRIME SUR TOUTES LES AUTRES ICI : un vendeur ne voit et
 *  ne modifie QUE ses propres sous-commandes. Le cloisonnement ne se
 *  vérifie pas « en général », il se vérifie à chaque requête — c'est
 *  pourquoi `boutiqueDeLUtilisateur()` filtre systématiquement, plutôt
 *  que de laisser chaque méthode y penser.
 *
 *  Les méthodes d'écriture sont laissées à écrire : marquer une
 *  sous-commande « livrée » déclenche la sortie de séquestre, donc du
 *  mouvement d'argent. Voir § 2.7 du dossier.
 * =====================================================================
 */
class VendeurController extends Controller
{
    /** Sous-commandes de la boutique de l'utilisateur connecté, et d'elle seule. */
    public function commandes(Request $r): JsonResponse
    {
        $boutiqueId = $r->user()?->boutique?->id;

        if (! $boutiqueId) {
            return response()->json(['message' => 'Aucune boutique rattachée à ce compte.'], 403);
        }

        return response()->json(
            SousCommande::query()
                ->where('boutique_id', $boutiqueId)
                ->with(['lignes', 'commande:id,reference,cree_le'])
                ->orderByDesc('id')
                ->paginate($r->integer('par_page', 25))
        );
    }

    /** À IMPLÉMENTER — passage à « expédiée » (§ 2.7). */
    public function expedier(Request $r, SousCommande $sousCommande): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté.'], 501);
    }

    /** À IMPLÉMENTER — livraison : déclenche la sortie de séquestre (§ 2.7, RG-29). */
    public function livrer(Request $r, SousCommande $sousCommande): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté.'], 501);
    }

    /** À IMPLÉMENTER. */
    public function produits(Request $r): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté.'], 501);
    }

    /** À IMPLÉMENTER. */
    public function creerProduit(Request $r): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté.'], 501);
    }

    /** À IMPLÉMENTER — historique des versements (§ 2.7, RG-36). */
    public function reversements(Request $r): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté.'], 501);
    }
}
