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
 *  retenue à la source. Ce contrôleur ne fait que valider la requête et
 *  démarrer l'encaissement via PaiementCommandeService — qui isole tout
 *  ce qui dépend d'un vrai prestataire de paiement.
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

        /*
         * RÉPONSE COMPOSÉE CHAMP PAR CHAMP, jamais le modèle brut. Le
         * modèle brut renvoyait tout ce que contient la table — dont le
         * code de retrait, c'est-à-dire la clé qui fait remettre le
         * colis et libère l'argent de la boutique. Une colonne ajoutée
         * demain ne doit pas apparaître ici sans qu'on l'ait décidé.
         */
        return response()->json([
            'reference'       => $commande->reference,
            'statut'          => $commande->statut,
            'statut_paiement' => $commande->statut_paiement,
            'mode_livraison'  => $commande->mode_livraison,
            'total_a_payer_cfa' => (int) $commande->total_a_payer_cfa,
            'passee_le'       => $commande->cree_le?->toIso8601String(),
            'colis'           => $commande->sousCommandes->map(fn ($sc) => [
                'reference'  => $sc->reference,
                'boutique'   => $sc->boutique?->nom,
                'statut'     => $sc->statut,
                'argent'     => $sc->etat_fonds?->libelle(),
                'expedie_le' => $sc->expedie_le?->toIso8601String(),
                'livre_le'   => $sc->livre_le?->toIso8601String(),
                'articles'   => $sc->lignes->map(fn ($l) => [
                    'nom'       => $l->nom_produit,
                    'variante'  => $l->libelle_variante,
                    'quantite'  => (int) $l->quantite,
                    'total_cfa' => (int) $l->total_ttc_cfa,
                ])->values(),
            ])->values(),
        ]);
    }

    /**
     * Crée une commande à partir d'un panier, puis démarre l'encaissement.
     * Le dénouement dépend de la passerelle configurée (App\Services\Paiement).
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
