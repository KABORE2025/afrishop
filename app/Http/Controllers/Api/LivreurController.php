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
    public function livrer(Request $r, SousCommande $sousCommande): JsonResponse
    {
        $data = $r->validate([
            'code_livraison' => ['required', 'digits:6'],
        ]);

        return DB::transaction(function () use ($r, $sousCommande, $data) {
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
            // Le code prouve la REMISE, pas la conformité du contenu : le
            // client découvre un produit cassé en ouvrant le paquet, une
            // fois le livreur reparti. Les fonds restent donc en séquestre
            // pendant la fenêtre de protection (LitigeService), et
            // `confirme_par_client_le` n'est posé que par le client lui-même.
            $sousCommande->update(['statut' => 'livree', 'livre_le' => now()]);

            $sousCommande->journaliser('livree', [], $r->user()->id, 'livreur');
            $sousCommande->commande->rafraichirStatut();

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
     * En dessous du plafond, la livraison reste affectée au même
     * livreur : il retente, avec le même code. Deux déclarations doivent
     * être espacées (paramètre `livraison_intervalle_tentatives_minutes`,
     * 120 par défaut) : trois « absent » saisis à la suite depuis le
     * même trottoir ne sont pas trois tentatives.
     *
     * Au plafond, le colis est déclaré EN RETOUR vers la boutique — et
     * c'est tout. Le livreur ne rembourse plus rien et ne remet rien en
     * stock : c'est la boutique qui, en recevant le colis, clôt le retour
     * (VendeurController::confirmerRetour(), ou l'admin à sa place). Un
     * acteur seul ne peut plus déclencher un remboursement par
     * déclaration.
     *
     * Compte les tentatives depuis le journal (`evenements_commande`)
     * plutôt que d'ajouter une colonne : `Expedition.tentatives` compte
     * déjà autre chose (les essais de CODE, dans `livrer()`) et les
     * mélanger casserait le blocage anti-bruteforce.
     */
    public function signalerEchec(Request $r, SousCommande $sousCommande): JsonResponse
    {
        $donnees = $r->validate([
            'motif'       => ['required', 'in:absent,adresse_introuvable,colis_refuse,autre'],
            'commentaire' => ['nullable', 'string', 'max:500'],
        ]);

        return DB::transaction(function () use ($r, $sousCommande, $donnees) {
            $sousCommande = SousCommande::whereKey($sousCommande->id)->lockForUpdate()->firstOrFail();
            $sousCommande->load(['expedition', 'commande']);
            $expedition = $sousCommande->expedition;

            if (! $expedition || $expedition->livreur_id !== $r->user()->id) {
                abort(403, 'Cette livraison ne vous est pas affectée.');
            }
            if ($sousCommande->statut !== 'expediee' || $expedition->statut !== 'en_cours') {
                return response()->json(['message' => "Cette livraison n'est plus à traiter."], 422);
            }

            $echecs = $sousCommande->evenements()->where('type', 'livraison_echouee');
            $tentative = (clone $echecs)->count() + 1;
            $max = (int) parametre('livraison_tentatives_max', 3);

            $intervalle = (int) parametre('livraison_intervalle_tentatives_minutes', 120);
            // Comparé à l'horloge de la base, qui a écrit `cree_le` :
            // mélanger l'heure PHP et l'heure SQL décale d'un fuseau.
            $tropRecent = $intervalle > 0 && (clone $echecs)
                ->whereRaw('cree_le > NOW() - INTERVAL ? MINUTE', [$intervalle])->exists();
            if ($tropRecent) {
                return response()->json([
                    'message' => "Échec déjà signalé il y a moins de {$intervalle} minutes. "
                        .'Retentez la livraison plus tard avant d’en déclarer un nouveau.',
                ], 422);
            }

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
            $sousCommande->journaliser('retour_expediteur_declare', [
                'tentatives' => $tentative, 'dernier_motif' => $donnees['motif'],
            ], $r->user()->id, 'livreur');

            return response()->json([
                'message' => 'Échec définitif : rapportez le colis à la boutique. Le client sera '
                    .'remboursé quand la boutique aura confirmé son retour.',
                'statut'  => 'retour_expediteur',
            ]);
        });
    }
}
