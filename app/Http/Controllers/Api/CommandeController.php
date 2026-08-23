<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CandidaterRequest;
use App\Http\Requests\CreerCommandeRequest;
use App\Models\Candidature;
use App\Models\Commande;
use App\Models\Pays;
use App\Services\Paiement\PaiementCommandeService;
use App\Services\PanierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * =====================================================================
 *  COMMANDES
 * =====================================================================
 *  `suivi()` est en lecture seule et sans règle métier.
 *
 *  `creer()` s'appuie entièrement sur PanierService::creerCommande(), qui
 *  fait déjà l'éclatement par boutique, la TVA, la commission et la
 *  retenue à la source. Ce contrôleur ne fait que : valider la requête,
 *  répondre pour le paiement à la livraison (rien de plus à faire), et
 *  démarrer l'encaissement pour les autres modes via PaiementCommandeService
 *  — qui isole tout ce qui dépend d'un vrai prestataire de paiement.
 * =====================================================================
 */
class CommandeController extends Controller
{
    public function __construct(
        private PanierService $panier,
        private PaiementCommandeService $paiement,
    ) {}

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

    /**
     * Crée une commande à partir d'un panier.
     *
     * Paiement à la livraison : c'est terminé, rien n'est encaissé (voir
     * PanierService). Les autres modes démarrent un encaissement dont le
     * dénouement dépend de la passerelle configurée (App\Services\Paiement).
     */
    public function creer(CreerCommandeRequest $r): JsonResponse
    {
        $data = $r->validated();
        $pays = Pays::findOrFail($data['pays_id']);

        try {
            $commande = $this->panier->creerCommande(
                articles: $data['articles'],
                client: [
                    'nom'             => $data['nom'],
                    'telephone'       => $data['telephone'],
                    'ville_id'        => $data['ville_id'] ?? null,
                    'quartier'        => $data['quartier'],
                    'repere'          => $data['repere'] ?? null,
                    'mode_livraison'  => $data['mode_livraison'] ?? 'domicile',
                    'mode_paiement'   => $data['mode_paiement'],
                    'cgv_version'     => $data['cgv_version'] ?? null,
                ],
                pays: $pays,
                utilisateurId: $r->user()?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($data['mode_paiement'] === 'especes_livraison') {
            return response()->json($commande, 201);
        }

        $resultat = $this->paiement->demarrerPaiement($commande);

        return match ($resultat['statut']) {
            'reussie' => response()->json($commande->fresh(['sousCommandes.lignes']), 201),
            'en_attente' => response()->json([
                'commande' => $commande,
                'paiement' => ['statut' => 'en_attente', 'url_paiement' => $resultat['url_paiement']],
            ], 202),
            default => response()->json([
                'message'  => "Le paiement a été refusé. La commande a été annulée et les articles remis en stock.",
                'commande' => $commande->fresh(),
            ], 422),
        };
    }

    /** Candidature d'une boutique. Traitée ensuite par un administrateur. */
    public function candidater(CandidaterRequest $r): JsonResponse
    {
        $candidature = Candidature::create($r->validated() + ['statut' => 'en_attente']);

        return response()->json([
            'message' => "Candidature enregistrée. Nous vous recontacterons sous quelques jours.",
            'id'      => $candidature->id,
        ], 201);
    }
}
