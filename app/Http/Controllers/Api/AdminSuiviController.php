<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\SousCommande;
use App\Services\SequestreService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * =====================================================================
 *  CONSOLE — SUIVI DES COMMANDES ET DU SÉQUESTRE (lecture seule)
 * =====================================================================
 *  Ce contrôleur ne modifie RIEN. Les actions (renvoyer un code, le
 *  consulter, clôturer un retour) restent dans AdminController, avec
 *  leur motif obligatoire et leur double journalisation.
 *
 *  1. CODES DE REMISE : on SUIT le code — généré ou non, envoyé quand,
 *     SMS remis ou en échec, combien de saisies ratées, validé ou non —
 *     sans jamais en renvoyer la valeur. Une liste qui afficherait les
 *     codes serait un fichier de secrets consultable en masse, alors
 *     que tout le reste du système est construit pour qu'il ne circule
 *     pas. Voir la valeur reste un acte isolé, motivé et tracé.
 *
 *  2. SÉQUESTRE : aucun bouton « libérer ». La libération n'a que trois
 *     chemins — confirmation du client, fin de la fenêtre de protection
 *     de 72 h (tâche horaire), arbitrage d'un litige — tous dans SequestreService. Un bouton qui
 *     forcerait la libération court-circuiterait précisément les garde-
 *     fous (litige, retour, colis non livré) qui protègent l'acheteur.
 *     Ce que l'admin doit pouvoir faire, c'est VOIR où est l'argent et
 *     POURQUOI il ne part pas.
 * =====================================================================
 */
class AdminSuiviController extends Controller
{
    public function __construct(private SequestreService $sequestre) {}

    // -----------------------------------------------------------------
    //  RECHERCHE
    // -----------------------------------------------------------------

    /**
     * Par référence (commande ou sous-commande, même partielle) ou par
     * téléphone du client — c'est ce que le client donne au support.
     */
    public function recherche(Request $r): JsonResponse
    {
        $donnees = $r->validate(['q' => ['required', 'string', 'min:3', 'max:40']]);
        $q = trim($donnees['q']);
        $chiffres = preg_replace('/\D/', '', $q);

        $resultats = SousCommande::query()
            ->with(['commande', 'boutique:id,nom'])
            ->where(function (Builder $w) use ($q, $chiffres) {
                $w->where('reference', 'like', "%{$q}%")
                  ->orWhereHas('commande', function (Builder $c) use ($q, $chiffres) {
                      $c->where('reference', 'like', "%{$q}%");
                      // Le téléphone se compare sur ses chiffres de fin :
                      // le client le donne rarement avec l'indicatif.
                      if (strlen($chiffres) >= 6) {
                          $c->orWhere('client_telephone', 'like', "%{$chiffres}");
                      }
                  });
            })
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return response()->json([
            'data' => $resultats->map(fn (SousCommande $sc) => $this->resume($sc))->values(),
        ]);
    }

    // -----------------------------------------------------------------
    //  FICHE
    // -----------------------------------------------------------------

    public function fiche(Request $r, SousCommande $sousCommande): JsonResponse
    {
        $sc = $sousCommande->load([
            'commande', 'boutique:id,nom', 'expedition.livreur:id,nom',
            'litiges', 'retours', 'evenements' => fn ($e) => $e->orderBy('cree_le')->orderBy('id'),
        ]);

        $retrait = $sc->commande->mode_livraison === 'retrait_boutique';
        $exp = $sc->expedition;

        return response()->json([
            ...$this->resume($sc),

            'client' => [
                'nom'       => $sc->commande->client_nom,
                'telephone' => $sc->commande->client_telephone,
                'quartier'  => $sc->commande->quartier,
                'repere'    => $sc->commande->repere,
            ],

            'expedition' => $exp ? [
                'statut'      => $exp->statut,
                'livreur'     => $exp->livreur?->nom,
                'motif_echec' => $exp->motif_echec,
                'expedie_le'  => $exp->expedie_le?->toIso8601String(),
                'livre_le'    => $exp->livre_le?->toIso8601String(),
            ] : null,

            /* Le code est SUIVI, jamais montré ici. */
            'code' => [
                'type'        => $retrait ? 'retrait' : 'livraison',
                'genere'      => $retrait ? $sc->code_retrait !== null : $exp?->code_livraison !== null,
                'tentatives'  => (int) ($retrait ? $sc->retrait_tentatives : $exp?->tentatives),
                'valide'      => $retrait ? $sc->statut === 'livree' : $exp?->statut === 'livree',
                'valide_le'   => $retrait
                    ? ($sc->statut === 'livree' ? $sc->livre_le?->toIso8601String() : null)
                    : $exp?->code_valide_le?->toIso8601String(),
                'sms'         => $this->smsDuCode($sc),
            ],

            'fonds' => $this->etatFonds($sc),

            'litiges' => $sc->litiges->map(fn ($l) => [
                'reference' => $l->reference, 'statut' => $l->statut, 'motif' => $l->motif,
            ])->values(),

            'retours' => $sc->retours->map(fn ($rt) => [
                'reference' => $rt->reference, 'statut' => $rt->statut, 'type' => $rt->type,
            ])->values(),

            'historique' => $sc->evenements->map(fn ($e) => [
                'type'        => $e->type,
                'auteur_type' => $e->auteur_type,
                'motif'       => ($e->donnees ?? [])['motif'] ?? ($e->donnees ?? [])['resolution'] ?? null,
                'le'          => $e->cree_le ? Carbon::parse($e->cree_le)->toIso8601String() : null,
            ])->values(),

            'actions' => [
                'peut_renvoyer_code' => $retrait
                    ? $sc->code_retrait !== null && $sc->statut !== 'livree'
                    : $exp?->code_livraison !== null && $exp->statut !== 'livree',
                'peut_voir_code' => $retrait ? $sc->code_retrait !== null : $exp?->code_livraison !== null,
                // Clôturer rembourse : réservé à l'admin (routes/api.php).
                'peut_cloturer_retour' => $r->user()->role === 'admin'
                    && $sc->statut === 'expediee' && $exp?->statut === 'retour_expediteur',
                'peut_ouvrir_litige'   => $sc->etat_fonds?->value === 'sequestre'
                    && ! $sc->litiges->contains(fn ($l) => $l->estOuvert()),
            ],
        ]);
    }

    // -----------------------------------------------------------------
    //  SÉQUESTRE
    // -----------------------------------------------------------------

    /**
     * Tout l'argent qui n'est pas encore parti, rangé par ce qui le
     * retient. Les étapes sont exclusives : une même sous-commande
     * n'apparaît que dans une seule.
     */
    public function sequestre(Request $r): JsonResponse
    {
        $donnees = $r->validate([
            'etape' => ['nullable', 'in:attente,livraison,delai,bloques'],
        ]);
        $etape = $donnees['etape'] ?? 'bloques';

        $totaux = [];
        foreach (['attente', 'livraison', 'delai', 'bloques'] as $e) {
            $base = $this->requeteEtape($e);
            $totaux[$e] = [
                'nombre'    => (clone $base)->count(),
                'montant_cfa' => (int) (clone $base)->sum('montant_net_cfa'),
            ];
        }

        $page = $this->requeteEtape($etape)
            ->with(['commande', 'boutique:id,nom'])
            ->orderBy('livre_le')->orderBy('id')
            ->paginate($r->integer('par_page', 25));

        return response()->json([
            'etape'  => $etape,
            'totaux' => $totaux,
            'prochaine_liberation_auto' => $this->prochainPassage(),
            'data'   => collect($page->items())->map(fn (SousCommande $sc) => [
                ...$this->resume($sc),
                'fonds' => $this->etatFonds($sc),
            ])->values(),
            'meta'   => [
                'page' => $page->currentPage(), 'dernier' => $page->lastPage(), 'total' => $page->total(),
            ],
        ]);
    }

    private function requeteEtape(string $etape): Builder
    {
        $bloque = function (Builder $q) {
            $q->whereHas('litiges', fn ($l) => $l->whereIn('statut', ['ouvert', 'en_examen']))
              ->orWhereHas('retours', fn ($rt) => $rt->whereIn('statut', ['demande', 'accepte', 'en_transit', 'recu']));
        };

        $q = SousCommande::query();

        return match ($etape) {
            'attente'   => $q->where('etat_fonds', 'attente_encaissement')
                             ->whereNotIn('statut', ['annulee']),
            'bloques'   => $q->where('etat_fonds', 'sequestre')->where($bloque),
            'livraison' => $q->where('etat_fonds', 'sequestre')->where('statut', '!=', 'livree')
                             ->whereNot($bloque),
            'delai'     => $q->where('etat_fonds', 'sequestre')->where('statut', 'livree')
                             ->whereNot($bloque),
        };
    }

    // -----------------------------------------------------------------

    private function resume(SousCommande $sc): array
    {
        return [
            'id'             => $sc->id,
            'reference'      => $sc->reference,
            'commande'       => $sc->commande->reference,
            'mode_livraison' => $sc->commande->mode_livraison,
            'mode_paiement'  => $sc->commande->mode_paiement,
            'client_nom'     => $sc->commande->client_nom,
            'boutique'       => $sc->boutique?->nom,
            'statut'         => $sc->statut,
            'etat_fonds'     => $sc->etat_fonds?->value,
            'etat_fonds_libelle' => $sc->etat_fonds?->libelle(),
            'montant_net_cfa'  => (int) $sc->montant_net_cfa,
            'montant_client_cfa' => (int) $sc->montant_articles_ttc_cfa + (int) $sc->frais_livraison_cfa - (int) $sc->remise_cfa,
            'passee_le'      => $sc->cree_le?->toIso8601String(),
            'livre_le'       => $sc->livre_le?->toIso8601String(),
        ];
    }

    /**
     * Où en est l'argent, et quand il partira. `liberation_prevue_le`
     * n'a de sens que si rien d'autre que le délai ne retient les fonds.
     */
    private function etatFonds(SousCommande $sc): array
    {
        $enSequestre = $sc->etat_fonds?->value === 'sequestre';
        $liberable = $enSequestre && $this->sequestre->liberables($sc);
        $delai = (int) parametre('delai_confirmation_auto_jours', 3);

        $prevue = null;
        if ($liberable) {
            $prevue = $this->prochainPassage();
        } elseif ($enSequestre && $sc->statut === 'livree' && $sc->livre_le
            && ! $sc->aUnLitigeOuvert() && ! $sc->aUnRetourEnCours()) {
            $prevue = $this->prochainPassage($sc->livre_le->copy()->addDays($delai));
        }

        return [
            'etat'                 => $sc->etat_fonds?->value,
            'libelle'              => $sc->etat_fonds?->libelle(),
            'liberable'            => $liberable,
            'raison'               => $liberable
                ? 'toutes les conditions sont réunies : libération au prochain passage automatique'
                : ($sc->etat_fonds?->estDefinitif() ? null : $this->sequestre->raisonBlocage($sc)),
            'confirme_par_client_le' => $sc->confirme_par_client_le?->toIso8601String(),
            'liberation_prevue_le' => $prevue,
        ];
    }

    /** Premier passage de `afrishop:liberer-fonds` (toutes les heures) à partir de $apres. */
    private function prochainPassage(?\DateTimeInterface $apres = null): string
    {
        $apres = Carbon::instance($apres ?? now());

        return $apres->copy()->startOfHour()->addHour()->toIso8601String();
    }

    /**
     * Les SMS de code envoyés au client pour cette sous-commande : statut
     * et date seulement. Les notifications ne sont pas rattachées à une
     * commande et leur texte est chiffré : on les retrouve par le
     * téléphone et la période, puis on garde celles qui contiennent la
     * référence ET le code. Le texte déchiffré et le code ne quittent
     * JAMAIS cette méthode.
     */
    private function smsDuCode(SousCommande $sc): array
    {
        $code = $sc->commande->mode_livraison === 'retrait_boutique'
            ? $sc->code_retrait
            : $sc->expedition?->code_livraison;

        if ($code === null) {
            return [];
        }

        return Notification::query()
            ->where('canal', 'sms')
            ->where('telephone', $sc->commande->client_telephone)
            ->where('cree_le', '>=', $sc->cree_le)
            ->orderBy('cree_le')
            ->limit(50)
            ->get()
            ->filter(fn (Notification $n) => str_contains((string) $n->corps_envoye, $sc->reference)
                && str_contains((string) $n->corps_envoye, $code))
            ->map(fn (Notification $n) => [
                'statut'    => $n->statut,
                'erreur'    => $n->erreur,
                'cree_le'   => $n->cree_le ? Carbon::parse($n->cree_le)->toIso8601String() : null,
                'envoye_le' => $n->envoye_le ? Carbon::parse($n->envoye_le)->toIso8601String() : null,
            ])
            ->values()
            ->all();
    }
}
