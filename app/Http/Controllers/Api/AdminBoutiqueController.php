<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\JournalAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * =====================================================================
 *  CONSOLE — BOUTIQUES (réservé à l'administrateur)
 * =====================================================================
 *  · vérifier le compte de paiement : sans cette vérification, la
 *    boutique ne vend pas et n'est jamais payée (Boutique::peutVendre(),
 *    ReversementService::motifDeSuspension()) ;
 *  · valider l'identité du gérant (KYC) : sans elle, les versements
 *    sont plafonnés à 200 000 F/mois (réglementation BCEAO) ;
 *  · suspendre ou réactiver une boutique.
 *
 *  Chaque geste est inscrit au journal d'administration, avec l'état
 *  avant et après : ce sont des décisions sur l'argent des vendeurs.
 * =====================================================================
 */
class AdminBoutiqueController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $donnees = $r->validate([
            'filtre' => ['nullable', 'in:tous,a_verifier,suspendues'],
            'q'      => ['nullable', 'string', 'max:60'],
        ]);

        $page = Boutique::query()
            ->with(['ville:id,nom', 'operateurPaiement:id,nom', 'utilisateur:id,nom,telephone,kyc_niveau'])
            ->when(($donnees['filtre'] ?? 'tous') === 'a_verifier', fn ($q) => $q
                ->whereNotNull('paiement_numero')->whereNull('paiement_verifie_le'))
            ->when(($donnees['filtre'] ?? null) === 'suspendues', fn ($q) => $q->where('statut', 'suspendu'))
            ->when($donnees['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w
                ->where('nom', 'like', "%{$t}%")->orWhere('code', 'like', "%{$t}%")->orWhere('telephone', 'like', "%{$t}%")))
            ->orderByRaw('paiement_numero is not null and paiement_verifie_le is null desc')
            ->orderBy('nom')
            ->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (Boutique $b) => [
                'id'         => $b->id,
                'code'       => $b->code,
                'nom'        => $b->nom,
                'statut'     => $b->statut,
                'ville'      => $b->ville?->nom,
                'gerant'     => $b->utilisateur ? [
                    'id' => $b->utilisateur->id, 'nom' => $b->utilisateur->nom,
                    'telephone' => $b->utilisateur->telephone, 'kyc' => $b->utilisateur->kyc_niveau,
                ] : null,
                'paiement'   => [
                    'operateur' => $b->operateurPaiement?->nom,
                    'numero'    => $b->paiement_numero,
                    'titulaire' => $b->paiement_titulaire,
                    'verifie_le'=> $b->paiement_verifie_le?->toIso8601String(),
                ],
                'peut_vendre' => $b->peutVendre(),
            ]),
            'meta' => ['page' => $page->currentPage(), 'dernier' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** L'admin a fait un petit virement de test et le titulaire l'a confirmé. */
    public function verifierPaiement(Request $r, Boutique $boutique): JsonResponse
    {
        $donnees = $r->validate([
            'numero'    => ['required', 'string'],
            'confirmation' => ['accepted'],
        ], ['confirmation.accepted' => 'Confirmez que le virement de test a bien été reçu par le titulaire.']);

        if ($boutique->paiement_numero === null) {
            return response()->json(['message' => 'La boutique n’a pas encore saisi de compte de paiement.'], 422);
        }
        // Le numéro vérifié est celui que l'admin a eu sous les yeux : si le
        // vendeur l'a changé entre-temps, on ne valide pas le nouveau à l'aveugle.
        if (preg_replace('/\D/', '', $donnees['numero']) !== $boutique->paiement_numero) {
            return response()->json(['message' => 'Le numéro a changé depuis l’affichage. Rechargez la page et vérifiez à nouveau.'], 422);
        }

        $boutique->update(['paiement_verifie_le' => now()]);
        $this->journaliser($r, 'paiement_verifie', $boutique, null, [
            'operateur_paiement_id' => $boutique->operateur_paiement_id,
            'numero' => $boutique->paiement_numero, 'titulaire' => $boutique->paiement_titulaire,
        ]);

        return response()->json(['message' => "Compte de paiement de « {$boutique->nom} » vérifié."]);
    }

    public function identification(Request $r, Boutique $boutique): JsonResponse
    {
        $donnees = $r->validate([
            'niveau' => ['required', 'in:aucun,simplifie,complet'],
            'motif'  => ['required', 'string', 'min:10', 'max:255'],
        ]);

        $gerant = $boutique->utilisateur;
        abort_unless($gerant, 422, 'Cette boutique n’a pas de gérant.');

        $avant = $gerant->kyc_niveau;
        $gerant->update(['kyc_niveau' => $donnees['niveau'], 'kyc_valide_le' => $donnees['niveau'] === 'aucun' ? null : now()]);
        $this->journaliser($r, 'kyc_modifie', $boutique, ['kyc' => $avant], ['kyc' => $donnees['niveau']], $donnees['motif']);

        return response()->json(['message' => 'Identification du gérant mise à jour.']);
    }

    public function suspendre(Request $r, Boutique $boutique): JsonResponse
    {
        $donnees = $r->validate(['motif' => ['required', 'string', 'min:10', 'max:255']]);

        if ($boutique->statut !== 'actif') {
            return response()->json(['message' => "Cette boutique est « {$boutique->statut} » : elle ne peut pas être suspendue."], 422);
        }

        $boutique->update(['statut' => 'suspendu']);
        $this->journaliser($r, 'boutique_suspendue', $boutique, ['statut' => 'actif'], ['statut' => 'suspendu'], $donnees['motif']);

        return response()->json([
            'message' => "« {$boutique->nom} » est suspendue : ses produits ne sont plus en vente. "
                .'Les commandes déjà passées suivent leur cours normal.',
        ]);
    }

    public function reactiver(Request $r, Boutique $boutique): JsonResponse
    {
        $donnees = $r->validate(['motif' => ['required', 'string', 'min:10', 'max:255']]);

        if ($boutique->statut !== 'suspendu') {
            return response()->json(['message' => 'Seule une boutique suspendue peut être réactivée.'], 422);
        }

        $boutique->update(['statut' => 'actif']);
        $this->journaliser($r, 'boutique_reactivee', $boutique, ['statut' => 'suspendu'], ['statut' => 'actif'], $donnees['motif']);

        return response()->json(['message' => "« {$boutique->nom} » est réactivée."]);
    }

    private function journaliser(Request $r, string $action, Boutique $b, ?array $avant, ?array $apres, ?string $motif = null): void
    {
        JournalAdministration::create([
            'agent_id'   => $r->user()->id,
            'action'     => $action,
            'cible_type' => 'boutique',
            'cible_id'   => $b->id,
            'avant'      => $avant,
            'apres'      => $apres,
            'motif'      => $motif,
            'ip_hachee'  => hash('sha256', (string) $r->ip()),
        ]);
    }
}
