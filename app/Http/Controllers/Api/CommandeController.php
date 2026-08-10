<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Services\PanierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * =====================================================================
 *  COMMANDES — CE CONTRÔLEUR EST UNE AMORCE, PAS UNE IMPLÉMENTATION
 * =====================================================================
 *  `suivi()` fonctionne : il est en lecture seule et sans règle métier.
 *
 *  `creer()` et `candidater()` sont volontairement laissés à écrire.
 *  Ce n'est pas de la paresse : créer une commande engage l'éclatement
 *  en sous-commandes, le calcul de commission, la retenue à la source,
 *  l'état des fonds et l'écriture au grand livre. Livrer une version
 *  approximative de tout cela serait pire que de ne rien livrer — les
 *  erreurs d'argent ne se voient qu'au premier reversement.
 *
 *  Les règles à respecter sont numérotées dans le dossier de conception
 *  (§ 2.6 et § 2.7, règles RG-27 à RG-36). Les services qui font le
 *  travail existent déjà : PanierService, TaxeService,
 *  RetenueSourceService, SequestreService, GrandLivreService.
 * =====================================================================
 */
class CommandeController extends Controller
{
    public function __construct(private PanierService $panier)
    {
    }

    /**
     * Suivi d'une commande.
     *
     * DEUX FACTEURS OBLIGATOIRES : référence ET téléphone. Les
     * références sont séquentielles, donc triviales à énumérer ; sans le
     * second facteur, n'importe qui lirait l'adresse de livraison d'un
     * inconnu. Cette règle n'est pas négociable (RG / § 3.3, BO-05).
     */
    public function suivi(Request $r): JsonResponse
    {
        $data = $r->validate([
            'reference'  => ['required', 'string', 'max:40'],
            'telephone'  => ['required', 'string', 'max:25'],
        ]);

        $commande = Commande::query()
            ->where('reference', $data['reference'])
            ->with(['sousCommandes.boutique:id,nom,emoji', 'sousCommandes.lignes'])
            ->first();

        // Comparaison sur les chiffres seuls : « +226 70 11 22 33 » et
        // « 22670112233 » désignent le même abonné.
        $chiffres = fn (?string $t) => preg_replace('/\D/', '', (string) $t);

        if (! $commande || $chiffres($commande->client_telephone) !== $chiffres($data['telephone'])) {
            // Message identique dans les deux cas : distinguer
            // « référence inconnue » de « téléphone faux » permettrait
            // d'énumérer les références valides.
            return response()->json(['message' => 'Commande introuvable.'], 404);
        }

        return response()->json($commande);
    }

    /** À IMPLÉMENTER — voir § 2.6 du dossier (éclatement, RG-27 à RG-36). */
    public function creer(Request $r): JsonResponse
    {
        return response()->json([
            'message' => "Non implémenté. La création de commande engage l'éclatement en "
                       . "sous-commandes, la commission, la retenue à la source et le grand "
                       . "livre : voir § 2.6 et § 2.7 du dossier de conception.",
        ], 501);
    }

    /** À IMPLÉMENTER — candidature d'une boutique (§ 2.13). */
    public function candidater(Request $r): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté.'], 501);
    }
}
