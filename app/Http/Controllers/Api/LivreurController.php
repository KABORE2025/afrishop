<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LivreurSousCommandeResource;
use App\Models\SousCommande;
use App\Services\SequestreService;
use App\Services\TableauBordLivreurService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LivreurController extends Controller
{
    /**
     * Les livraisons du livreur connecté, et de lui seul.
     *
     * Par défaut : seulement celles en cours (`expediee`) — c'est l'écran
     * de travail du jour. `?statut[]=livree` etc. donne l'historique :
     * `expedition.livreur_id` ne change jamais après affectation, donc
     * une livraison terminée reste retrouvable par ce même filtre.
     */
    public function commandes(Request $r): JsonResponse
    {
        $statuts = $r->filled('statut') ? (array) $r->input('statut') : ['expediee'];

        $commandes = SousCommande::query()
            ->whereIn('statut', $statuts)
            ->whereHas('expedition', fn ($q) => $q->where('livreur_id', $r->user()->id))
            ->with(['expedition', 'commande'])
            ->orderByDesc('expedie_le')
            ->paginate($r->integer('par_page', 25));

        return response()->json(LivreurSousCommandeResource::collection($commandes)->response()->getData(true));
    }

    /** Compteurs d'activité — aucune donnée financière : voir TableauBordLivreurService. */
    public function tableauDeBord(Request $r, TableauBordLivreurService $service): JsonResponse
    {
        return response()->json($service->pour($r->user()));
    }

    /**
     * Preuve de remise : le code reste exclusivement chez le client et
     * n'est accepté que du livreur auquel l'expédition a été affectée.
     */
    public function livrer(Request $r, SousCommande $sousCommande, SequestreService $sequestre): JsonResponse
    {
        $data = $r->validate([
            'code_livraison' => ['required', 'digits:6'],
        ]);

        return DB::transaction(function () use ($r, $sousCommande, $data, $sequestre) {
            $sousCommande->load(['expedition', 'commande']);
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

            $expedition->update(['statut' => 'livree', 'livre_le' => now(), 'code_valide_le' => now()]);
            // Saisir CE code prouve que c'est bien le client qui l'a
            // communiqué : c'est une confirmation de réception aussi
            // solide qu'un client qui cliquerait « j'ai bien reçu ». La
            // marquer ainsi évite d'attendre le délai de 3 jours pour
            // rien — voir la même remarque dans VendeurController::confirmerRetrait().
            $sousCommande->update(['statut' => 'livree', 'livre_le' => now(), 'confirme_par_client_le' => now()]);

            $sousCommande->journaliser('livree', [], $r->user()->id, 'livreur');
            $sousCommande->commande->rafraichirStatut();

            $sousCommande->refresh();
            if ($sequestre->liberables($sousCommande)) {
                $sequestre->liberer($sousCommande, 'client');
            }

            return response()->json(new LivreurSousCommandeResource($sousCommande->fresh(['expedition', 'commande'])));
        });
    }

    /**
     * Échec de remise — client absent, adresse introuvable, colis
     * refusé. Sans cette route, un échec n'avait AUCUNE issue : le
     * livreur restait bloqué devant une porte fermée, sans rien pouvoir
     * faire remonter, et la sous-commande restait « expediee » pour
     * toujours.
     *
     * En dessous du plafond de tentatives, la livraison reste affectée
     * au même livreur : il peut simplement retenter, avec le même code.
     * Au plafond, le colis est déclaré retourné et le client remboursé
     * — via SequestreService, jamais une écriture composée ici — et le
     * stock est rendu, exactement comme un paiement qui échoue.
     *
     * Compte les tentatives depuis le journal (`evenements_commande`)
     * plutôt que d'ajouter une colonne : `Expedition.tentatives` compte
     * déjà autre chose (les essais de CODE, dans `livrer()`) et les
     * mélanger casserait le blocage anti-bruteforce.
     */
    public function signalerEchec(Request $r, SousCommande $sousCommande, SequestreService $sequestre): JsonResponse
    {
        $donnees = $r->validate([
            'motif'       => ['required', 'in:absent,adresse_introuvable,colis_refuse,autre'],
            'commentaire' => ['nullable', 'string', 'max:500'],
        ]);

        return DB::transaction(function () use ($r, $sousCommande, $donnees, $sequestre) {
            $sousCommande = SousCommande::whereKey($sousCommande->id)->lockForUpdate()->firstOrFail();
            $sousCommande->load(['expedition', 'commande', 'lignes.variante']);
            $expedition = $sousCommande->expedition;

            if (! $expedition || $expedition->livreur_id !== $r->user()->id) {
                abort(403, 'Cette livraison ne vous est pas affectée.');
            }
            if ($sousCommande->statut !== 'expediee' || $expedition->statut !== 'en_cours') {
                return response()->json(['message' => "Cette livraison n'est plus à traiter."], 422);
            }

            $tentative = $sousCommande->evenements()->where('type', 'livraison_echouee')->count() + 1;
            $max = (int) parametre('livraison_tentatives_max', 3);

            $sousCommande->journaliser('livraison_echouee', [
                'motif'       => $donnees['motif'],
                'commentaire' => $donnees['commentaire'] ?? null,
                'tentative'   => $tentative,
            ], $r->user()->id, 'livreur');

            if ($tentative < $max) {
                return response()->json([
                    'message' => "Échec signalé ({$tentative}/{$max}). Vous pouvez retenter la livraison.",
                    'statut'  => 'en_cours',
                ]);
            }

            $expedition->update(['statut' => 'retour_expediteur']);

            $sequestre->rembourser(
                $sousCommande,
                "Colis retourné après {$tentative} échecs de livraison (dernier motif : {$donnees['motif']})."
            );

            foreach ($sousCommande->lignes as $ligne) {
                $ligne->variante?->increment('stock', $ligne->quantite);
            }

            return response()->json([
                'message' => 'Échec définitif : le colis est marqué retourné et le client sera remboursé.',
                'statut'  => 'retour_expediteur',
            ]);
        });
    }
}
