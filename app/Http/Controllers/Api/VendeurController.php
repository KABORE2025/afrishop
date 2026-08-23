<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreerProduitRequest;
use App\Models\Boutique;
use App\Models\Expedition;
use App\Models\Produit;
use App\Models\Reversement;
use App\Models\SousCommande;
use App\Services\EspecesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 *  ESPACE VENDEUR
 * =====================================================================
 *  Toutes ces routes passent par `auth:sanctum` et le middleware `role`
 *  déclarés dans routes/api.php.
 *
 *  LA RÈGLE QUI PRIME SUR TOUTES LES AUTRES ICI : un vendeur ne voit et
 *  ne modifie QUE ses propres sous-commandes. `resoudreBoutique()`
 *  centralise ce contrôle plutôt que de laisser chaque méthode y penser.
 * =====================================================================
 */
class VendeurController extends Controller
{
    public function __construct(private EspecesService $especes) {}

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

    /**
     * Passage à « expédiée ». Crée l'expédition (code de suivi, code de
     * livraison à usage unique) et, en paiement à la livraison, prépare
     * l'encaissement du livreur.
     */
    public function expedier(Request $r, SousCommande $sousCommande): JsonResponse
    {
        $boutique = $this->resoudreBoutique($r, $sousCommande);

        if (! in_array($sousCommande->statut, ['a_preparer', 'prete'], true)) {
            return response()->json([
                'message' => "Cette sous-commande est « {$sousCommande->statut} » : elle ne peut plus être expédiée.",
            ], 422);
        }

        $data = $r->validate([
            'transporteur_id' => ['nullable', 'integer', 'exists:transporteurs,id'],
            'code_suivi'      => ['nullable', 'string', 'max:60'],
        ]);

        DB::transaction(function () use ($sousCommande, $data, $r) {
            $expedition = $sousCommande->expedition ?? new Expedition(['sous_commande_id' => $sousCommande->id]);
            $expedition->fill([
                'transporteur_id' => $data['transporteur_id'] ?? $expedition->transporteur_id,
                'code_suivi'      => $data['code_suivi'] ?? $expedition->code_suivi,
                // Généré une seule fois : ré-expédier ne doit pas changer
                // le code déjà communiqué au client par SMS.
                'code_livraison'  => $expedition->code_livraison ?? (string) random_int(100000, 999999),
                'statut'          => 'en_cours',
                'expedie_le'      => now(),
            ]);
            $expedition->save();

            if ($sousCommande->commande->mode_paiement === 'especes_livraison'
                && ! $expedition->encaissementEspeces()->exists()) {
                $this->especes->preparer($expedition->fresh());
            }

            $sousCommande->update(['statut' => 'expediee', 'expedie_le' => now()]);
            $sousCommande->journaliser('expediee', ['code_suivi' => $data['code_suivi'] ?? null], $r->user()->id, 'vendeur');
        });

        return response()->json($sousCommande->fresh(['expedition']));
    }

    /**
     * Livraison. En paiement à la livraison, exige le montant réellement
     * perçu et déclenche l'écriture au grand livre (EspecesService).
     * En paiement en ligne, les fonds sont déjà en séquestre depuis la
     * création : il n'y a rien de plus à faire côté argent.
     */
    public function livrer(Request $r, SousCommande $sousCommande): JsonResponse
    {
        $this->resoudreBoutique($r, $sousCommande);

        if ($sousCommande->statut !== 'expediee') {
            return response()->json([
                'message' => "Cette sous-commande est « {$sousCommande->statut} » : elle doit être expédiée avant d'être livrée.",
            ], 422);
        }

        $expedition = $sousCommande->expedition;
        $especesLivraison = $sousCommande->commande->mode_paiement === 'especes_livraison';

        $data = $r->validate([
            'code_livraison'    => ['required', 'string'],
            'montant_percu_cfa' => [$especesLivraison ? 'required' : 'nullable', 'integer', 'min:0'],
        ]);

        if ($expedition->code_livraison !== null && $data['code_livraison'] !== $expedition->code_livraison) {
            return response()->json(['message' => "Le code de livraison ne correspond pas."], 422);
        }

        DB::transaction(function () use ($sousCommande, $expedition, $especesLivraison, $data, $r) {
            $expedition->update(['statut' => 'livree', 'livre_le' => now(), 'code_valide_le' => now()]);
            $sousCommande->update(['statut' => 'livree', 'livre_le' => now()]);

            if ($especesLivraison) {
                $encaissement = $expedition->encaissementEspeces;
                if ($encaissement) {
                    $this->especes->encaisser($encaissement, (int) $data['montant_percu_cfa']);
                }
            }

            $sousCommande->journaliser('livree', [], $r->user()->id, 'vendeur');
            $sousCommande->commande->rafraichirStatut();
        });

        return response()->json($sousCommande->fresh(['expedition']));
    }

    /** Produits de la boutique, avec leurs variantes. */
    public function produits(Request $r): JsonResponse
    {
        $boutique = $this->resoudreBoutique($r);

        return response()->json(
            Produit::where('boutique_id', $boutique->id)
                ->with('variantes')
                ->orderByDesc('id')
                ->paginate($r->integer('par_page', 25))
        );
    }

    /**
     * Création d'un produit. Publié après modération (`statut_moderation`
     * démarre à « en_attente » — valeur par défaut de la table) : ce
     * contrôleur ne rend pas la fiche visible tout seul.
     */
    public function creerProduit(CreerProduitRequest $r): JsonResponse
    {
        $boutique = $this->resoudreBoutique($r);
        $data = $r->validated();

        $produit = DB::transaction(function () use ($boutique, $data) {
            $produit = Produit::create([
                'boutique_id'  => $boutique->id,
                'categorie_id' => $data['categorie_id'],
                'reference'    => Produit::prochaineReference($boutique),
                'nom'          => $data['nom'],
                'slug'         => Produit::slugUnique($data['nom']),
                'description'  => $data['description'] ?? null,
                'prix_ttc_cfa' => $data['prix_ttc_cfa'],
                'poids_g'      => $data['poids_g'] ?? null,
                'tracable'     => $data['tracable'] ?? false,
            ]);

            $variantes = $data['variantes'] ?? [[
                'libelle' => 'Standard', 'stock' => 0, 'seuil_alerte' => 3,
            ]];

            foreach ($variantes as $i => $v) {
                $produit->variantes()->create([
                    'sku'           => $produit->reference . '-V' . ($i + 1),
                    'libelle'       => $v['libelle'],
                    'prix_ttc_cfa'  => $v['prix_ttc_cfa'] ?? null,
                    'stock'         => $v['stock'],
                    'seuil_alerte'  => $v['seuil_alerte'] ?? 3,
                    'defaut'        => $i === 0,
                ]);
            }

            return $produit;
        });

        return response()->json($produit->fresh('variantes'), 201);
    }

    /** Historique des reversements de la boutique. */
    public function reversements(Request $r): JsonResponse
    {
        $boutique = $this->resoudreBoutique($r);

        return response()->json(
            Reversement::where('boutique_id', $boutique->id)
                ->orderByDesc('id')
                ->paginate($r->integer('par_page', 25))
        );
    }

    /**
     * Boutique de l'utilisateur connecté. Si une sous-commande est
     * fournie, vérifie en plus qu'elle lui appartient — c'est le
     * cloisonnement qui prime sur tout le reste de ce contrôleur.
     */
    private function resoudreBoutique(Request $r, ?SousCommande $sousCommande = null): Boutique
    {
        $boutique = $r->user()?->boutique;

        if (! $boutique) {
            abort(403, 'Aucune boutique rattachée à ce compte.');
        }

        if ($sousCommande && $sousCommande->boutique_id !== $boutique->id) {
            abort(403, "Cette sous-commande n'appartient pas à votre boutique.");
        }

        return $boutique;
    }
}
