<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Avis;
use App\Models\JournalAdministration;
use App\Models\Parametre;
use App\Models\Pays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * =====================================================================
 *  CONSOLE — RÉGLAGES, JOURNAL, AVIS (réservé à l'administrateur)
 * =====================================================================
 *  RÉGLAGES : seules les règles listées dans CATALOGUE se modifient
 *  ici, chacune avec ses BORNES. Une commission à 100 % ou un délai de
 *  protection à 0 jour sont des fautes de frappe aux conséquences
 *  immédiates sur l'argent : elles sont refusées. Chaque changement est
 *  inscrit au journal, avec l'ancienne et la nouvelle valeur.
 * =====================================================================
 */
class AdminReglagesController extends Controller
{
    /** clé => [libellé, unité, min, max, défaut du code] */
    private const CATALOGUE = [
        'delai_confirmation_auto_jours'          => ['Fenêtre de protection du client après la remise', 'jours', 1, 15, 3],
        'reversement_minimum_cfa'                => ['Montant minimum d’un versement au vendeur', 'FCFA', 0, 100_000, 5000],
        'paiement_delai_expiration_minutes'      => ['Délai pour payer avant annulation de la commande', 'minutes', 10, 1440, 30],
        'livraison_tentatives_max'               => ['Tentatives de livraison avant retour à la boutique', 'tentatives', 1, 10, 3],
        'livraison_intervalle_tentatives_minutes'=> ['Délai minimum entre deux échecs de livraison', 'minutes', 0, 1440, 120],
        'panier_max_mobile_money_cfa'            => ['Panier maximum payable en Mobile Money', 'FCFA', 10_000, 10_000_000, 1_500_000],
        'plafond_wallet_identifie_cfa'           => ['Plafond de versement, gérant identifié (BCEAO)', 'FCFA', 100_000, 10_000_000, 2_000_000],
        'plafond_wallet_non_identifie_mensuel_cfa' => ['Plafond mensuel, gérant non identifié (BCEAO)', 'FCFA', 50_000, 2_000_000, 200_000],
    ];

    public function parametres(): JsonResponse
    {
        $valeurs = Parametre::whereNull('pays_id')->whereIn('cle', array_keys(self::CATALOGUE))->pluck('valeur', 'cle');

        return response()->json(['data' => collect(self::CATALOGUE)->map(fn ($d, $cle) => [
            'cle' => $cle, 'libelle' => $d[0], 'unite' => $d[1], 'min' => $d[2], 'max' => $d[3],
            'valeur' => (int) ($valeurs[$cle] ?? $d[4]), 'defaut' => $d[4],
        ])->values()]);
    }

    public function modifierParametre(Request $r, string $cle): JsonResponse
    {
        abort_unless(isset(self::CATALOGUE[$cle]), 404, 'Réglage inconnu.');
        [$libelle, $unite, $min, $max, $defaut] = self::CATALOGUE[$cle];

        $donnees = $r->validate([
            'valeur' => ['required', 'integer', "min:{$min}", "max:{$max}"],
            'motif'  => ['required', 'string', 'min:10', 'max:255'],
        ], [
            'valeur.min' => "Valeur trop basse : {$min} {$unite} au minimum.",
            'valeur.max' => "Valeur trop haute : {$max} {$unite} au maximum.",
        ]);

        $ligne = Parametre::firstOrNew(['pays_id' => null, 'cle' => $cle]);
        $avant = $ligne->exists ? $ligne->valeur : (string) $defaut;
        $ligne->fill(['valeur' => (string) $donnees['valeur'], 'type' => 'entier', 'libelle' => $ligne->libelle ?? $libelle])->save();

        // Le cache de parametre() est indexé par pays : on vide toutes les entrées.
        foreach ([null, ...Pays::pluck('id')->all()] as $paysId) {
            Cache::forget("parametre:{$cle}:{$paysId}");
        }

        $this->journaliser($r, 'parametre_modifie', 'parametre', $ligne->id, ['valeur' => $avant], ['valeur' => (string) $donnees['valeur']], $donnees['motif'], ['cle' => $cle]);

        return response()->json(['message' => "« {$libelle} » : {$donnees['valeur']} {$unite}."]);
    }

    public function journal(Request $r): JsonResponse
    {
        $page = JournalAdministration::query()
            ->leftJoin('utilisateurs', 'utilisateurs.id', '=', 'journal_administration.agent_id')
            ->when($r->input('action'), fn ($q, $a) => $q->where('journal_administration.action', $a))
            ->orderByDesc('journal_administration.id')
            ->select('journal_administration.*', 'utilisateurs.nom as agent_nom')
            ->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn ($j) => [
                'id' => $j->id, 'action' => $j->action, 'agent' => $j->agent_nom,
                'cible' => $j->cible_type.($j->cible_id ? ' #'.$j->cible_id : ''),
                'avant' => $j->avant, 'apres' => $j->apres, 'motif' => $j->motif,
                'le' => $j->cree_le ? \Illuminate\Support\Carbon::parse($j->cree_le)->toIso8601String() : null,
            ]),
            'actions' => JournalAdministration::distinct()->orderBy('action')->pluck('action'),
            'meta' => ['page' => $page->currentPage(), 'dernier' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function avis(Request $r): JsonResponse
    {
        $page = Avis::with(['produit:id,nom,slug', 'boutique:id,nom'])
            ->where('statut', $r->input('statut', 'publie'))
            ->orderByDesc('id')->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (Avis $a) => [
                'id' => $a->id, 'note' => $a->note, 'commentaire' => $a->commentaire, 'auteur' => $a->auteur_affiche,
                'produit' => $a->produit?->nom, 'slug' => $a->produit?->slug, 'boutique' => $a->boutique?->nom,
                'motif_rejet' => $a->motif_rejet, 'le' => $a->cree_le?->toIso8601String(),
            ]),
            'meta' => ['page' => $page->currentPage(), 'dernier' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Retirer un avis abusif (insulte, numéro de téléphone, hors sujet). */
    public function retirerAvis(Request $r, Avis $avis): JsonResponse
    {
        $donnees = $r->validate(['motif' => ['required', 'string', 'min:10', 'max:255']]);

        $avis->update(['statut' => 'rejete', 'motif_rejet' => $donnees['motif']]);
        $this->journaliser($r, 'avis_retire', 'avis', $avis->id, ['statut' => 'publie'], ['statut' => 'rejete'], $donnees['motif']);

        return response()->json(['message' => 'Avis retiré de la vitrine.']);
    }

    private function journaliser(Request $r, string $action, string $type, ?int $id, ?array $avant, ?array $apres, ?string $motif, array $extra = []): void
    {
        JournalAdministration::create([
            'agent_id' => $r->user()->id, 'action' => $action, 'cible_type' => $type, 'cible_id' => $id,
            'avant' => $avant ? $avant + $extra : null, 'apres' => $apres ? $apres + $extra : null,
            'motif' => $motif, 'ip_hachee' => hash('sha256', (string) $r->ip()),
        ]);
    }
}
