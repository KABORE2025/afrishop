<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LivreurSousCommandeResource;
use App\Models\SousCommande;
use App\Services\EspecesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LivreurController extends Controller
{
    public function __construct(private EspecesService $especes) {}

    /** Seules les expéditions explicitement affectées au livreur connecté. */
    public function commandes(Request $r): JsonResponse
    {
        $commandes = SousCommande::query()
            ->where('statut', 'expediee')
            ->whereHas('expedition', fn ($q) => $q->where('livreur_id', $r->user()->id))
            ->with(['expedition', 'commande'])
            ->orderByDesc('expedie_le')
            ->paginate($r->integer('par_page', 25));

        return response()->json(LivreurSousCommandeResource::collection($commandes)->response()->getData(true));
    }

    /**
     * Preuve de remise : le code reste exclusivement chez le client et
     * n'est accepté que du livreur auquel l'expédition a été affectée.
     */
    public function livrer(Request $r, SousCommande $sousCommande): JsonResponse
    {
        $data = $r->validate([
            'code_livraison' => ['required', 'digits:6'],
            'montant_percu_cfa' => ['nullable', 'integer', 'min:0'],
        ]);

        return DB::transaction(function () use ($r, $sousCommande, $data) {
            $sousCommande->load(['expedition.encaissementEspeces', 'commande', 'lignes.variante']);
            $expedition = $sousCommande->expedition;

            if (! $expedition || $expedition->livreur_id !== $r->user()->id) {
                abort(403, 'Cette livraison ne vous est pas affectée.');
            }
            if ($sousCommande->statut !== 'expediee' || $expedition->statut !== 'en_cours') {
                return response()->json(['message' => "Cette livraison n'est plus à valider."], 422);
            }
            if ($expedition->tentatives >= 5) {
                return response()->json(['message' => 'Code bloqué après 5 essais. Contactez le support Afrishop.'], 422);
            }
            if (! hash_equals((string) $expedition->code_livraison, $data['code_livraison'])) {
                $tentatives = (int) $expedition->tentatives + 1;
                $expedition->update(['tentatives' => $tentatives]);
                $restantes = 5 - $tentatives;

                return response()->json(['message' => "Code incorrect. {$restantes} essai(s) restant(s)."], 422);
            }

            $especes = $sousCommande->commande->mode_paiement === 'especes_livraison';
            if ($especes && ! isset($data['montant_percu_cfa'])) {
                return response()->json(['message' => 'Le montant encaissé est requis pour un paiement à la livraison.'], 422);
            }

            $expedition->update(['statut' => 'livree', 'livre_le' => now(), 'code_valide_le' => now()]);
            $sousCommande->update(['statut' => 'livree', 'livre_le' => now()]);

            if ($especes) {
                $encaissement = $expedition->encaissementEspeces ?: $this->especes->preparer($expedition);
                $this->especes->encaisser($encaissement, (int) $data['montant_percu_cfa']);
            }

            $sousCommande->journaliser('livree', [], $r->user()->id, 'livreur');
            $sousCommande->commande->rafraichirStatut();

            return response()->json(new LivreurSousCommandeResource($sousCommande->fresh(['expedition', 'commande'])));
        });
    }
}
