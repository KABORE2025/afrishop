<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CandidatureResource;
use App\Http\Resources\LitigeResource;
use App\Http\Resources\ProduitResource;
use App\Http\Resources\ReversementResource;
use App\Models\Boutique;
use App\Models\Candidature;
use App\Models\JournalAdministration;
use App\Models\Litige;
use App\Models\Pays;
use App\Models\Produit;
use App\Models\Reversement;
use App\Models\SousCommande;
use App\Models\Utilisateur;
use App\Services\CodeRemiseService;
use App\Services\LitigeService;
use App\Services\SequestreService;
use App\Services\TableauBordAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 *  CONSOLE AFRISHOP
 * =====================================================================
 *  Toutes ces routes passent par `auth:sanctum` et `role:admin`
 *  (déclarés dans routes/api.php). `Utilisateur::estAdmin()` couvre
 *  aussi le rôle `agent`.
 *
 *  DEUX PRINCIPES TENUS ICI, ET NULLE PART AILLEURS :
 *
 *  1. AUCUN MOUVEMENT D'ARGENT N'EST ÉCRIT À LA MAIN DANS CE FICHIER.
 *     L'arbitrage d'un litige appelle SequestreService — le même code
 *     que la libération automatique. Écrire une écriture au grand livre
 *     depuis un contrôleur produirait deux chemins pour la même
 *     opération, et un jour ils divergeraient.
 *
 *  2. TOUTE DÉCISION EST MOTIVÉE ET HORODATÉE. Un refus sans motif et
 *     un arbitrage sans explication sont indéfendables le jour où on
 *     les conteste. Les champs sont obligatoires, pas conseillés.
 * =====================================================================
 */
class AdminController extends Controller
{
    public function __construct(private SequestreService $sequestre) {}

    /** Ce qui attend une décision humaine. */
    public function tableauDeBord(TableauBordAdminService $service): JsonResponse
    {
        return response()->json($service->resume());
    }

    // -----------------------------------------------------------------
    //  CANDIDATURES
    // -----------------------------------------------------------------

    public function candidatures(Request $r): JsonResponse
    {
        $requete = Candidature::query();

        // Par défaut, ce qui reste à traiter : une console qui s'ouvre
        // sur l'historique complet fait chercher le travail du jour.
        $requete->whereIn('statut', (array) $r->input('statut', ['en_attente', 'en_verification']));

        return response()->json(
            CandidatureResource::collection(
                $requete->orderBy('cree_le')->paginate($r->integer('par_page', 25))
            )->response()->getData(true)
        );
    }

    /**
     * Acceptation : crée la boutique, rattache ou crée son gérant, et
     * lie le tout à la candidature.
     *
     * Le compte du gérant reçoit un mot de passe temporaire, renvoyé
     * UNE SEULE FOIS dans la réponse. Ce n'est pas la solution finale :
     * il devra partir par SMS via NotificationService le jour où le
     * gabarit correspondant existera. En attendant, mieux vaut un
     * secret que l'administrateur communique de vive voix qu'un compte
     * sans mot de passe, que personne ne peut utiliser.
     */
    public function accepterCandidature(Request $r, Candidature $candidature): JsonResponse
    {
        if ($candidature->statut === 'acceptee') {
            return response()->json(['message' => 'Cette candidature a déjà été acceptée.'], 422);
        }

        $donnees = $r->validate([
            'taux_commission' => ['nullable', 'integer', 'min:0', 'max:20'],
            'niveau'          => ['nullable', 'in:nouveau,verifie,interne'],
        ]);

        // Le formulaire de candidature est PUBLIC : n'importe qui peut y
        // saisir le numéro d'un livreur, d'un vendeur ou d'un membre du
        // personnel. Accepter aurait alors transformé ce compte en
        // « vendeur » — un livreur perdait ses livraisons, un admin son
        // accès. Seul un simple compte client peut devenir vendeur.
        $existant = Utilisateur::where('telephone', $candidature->telephone)->first();
        if ($existant && $existant->role !== 'client') {
            $libelle = ['vendeur' => 'd’un vendeur', 'livreur' => 'd’un livreur',
                'agent' => 'd’un agent Afrishop', 'admin' => 'd’un administrateur'][$existant->role] ?? 'd’un autre compte';

            return response()->json([
                'message' => "Ce téléphone est déjà celui {$libelle}. Refusez la candidature, ou demandez "
                    .'au candidat un autre numéro : un compte existant ne change jamais de rôle.',
            ], 422);
        }

        $resultat = DB::transaction(function () use ($candidature, $donnees) {

            // Un compte existe peut-être déjà avec ce numéro : le
            // promouvoir plutôt qu'en créer un second, sinon le vendeur
            // se retrouve avec deux comptes et un historique coupé.
            $utilisateur = Utilisateur::where('telephone', $candidature->telephone)->first();
            $motDePasse  = null;

            if ($utilisateur) {
                $utilisateur->update(['role' => 'vendeur']);
            } else {
                $motDePasse = Str::random(10);
                $utilisateur = Utilisateur::create([
                    'pays_id'      => $candidature->pays_id,
                    'nom'          => $candidature->responsable,
                    'telephone'    => $candidature->telephone,
                    'mot_de_passe' => $motDePasse,
                    'role'         => 'vendeur',
                ]);
            }

            $boutique = Boutique::create([
                'utilisateur_id'  => $utilisateur->id,
                'pays_id'         => $candidature->pays_id,
                'ville_id'        => $candidature->ville_id,
                'code'            => $this->prochainCodeBoutique($candidature->pays_id),
                'nom'             => $candidature->nom_boutique,
                'slug'            => $this->slugUnique($candidature->nom_boutique),
                'description'     => $candidature->description,
                'telephone'       => $candidature->telephone,
                'taux_commission' => $donnees['taux_commission'] ?? config('afrishop.commission_defaut'),
                // « nouveau » par défaut : le séquestre le plus long
                // s'applique tant que la boutique n'a pas fait ses
                // preuves. Accorder « verifie » d'emblée reviendrait à
                // supprimer la protection de l'acheteur pour un vendeur
                // dont on ne sait encore rien.
                'niveau'          => $donnees['niveau'] ?? 'nouveau',
                'statut'          => 'actif',
                'vend_en_ligne'   => true,
            ]);

            $candidature->update([
                'statut'        => 'acceptee',
                'boutique_id'   => $boutique->id,
                'traite_par_id' => request()->user()->id,
                'traite_le'     => now(),
            ]);

            return [$boutique, $utilisateur, $motDePasse];
        });

        [$boutique, $utilisateur, $motDePasse] = $resultat;

        return response()->json([
            'message'  => "Boutique « {$boutique->nom} » créée sous le code {$boutique->code}.",
            'boutique' => $boutique->only(['id', 'code', 'nom', 'slug', 'niveau', 'taux_commission']),
            'gerant'   => [
                'nom'       => $utilisateur->nom,
                'telephone' => $utilisateur->telephone,
                /* NULL si le compte existait déjà : dans ce cas il garde
                 * son mot de passe, et le lui « réinitialiser » sans le
                 * prévenir le mettrait dehors. */
                'mot_de_passe_temporaire' => $motDePasse,
            ],
            'a_communiquer' => $motDePasse
                ? 'Transmettez ce mot de passe au gérant : il ne sera plus affiché.'
                : 'Ce numéro avait déjà un compte : le gérant garde son mot de passe actuel.',
        ], 201);
    }

    /** Refus. Le motif est obligatoire : un refus muet est incontestable. */
    public function refuserCandidature(Request $r, Candidature $candidature): JsonResponse
    {
        $donnees = $r->validate([
            'motif' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $candidature->update([
            'statut'        => 'refusee',
            'motif_refus'   => $donnees['motif'],
            'traite_par_id' => $r->user()->id,
            'traite_le'     => now(),
        ]);

        return response()->json(new CandidatureResource($candidature->fresh()));
    }

    // -----------------------------------------------------------------
    //  LITIGES
    // -----------------------------------------------------------------

    public function litiges(Request $r): JsonResponse
    {
        $requete = Litige::query()->with(['sousCommande', 'photos']);
        $requete->whereIn('statut', (array) $r->input('statut', ['ouvert', 'en_examen']));

        return response()->json(
            LitigeResource::collection(
                $requete->orderBy('ouvert_le')->paginate($r->integer('par_page', 25))
            )->response()->getData(true)
        );
    }

    /**
     * ARBITRAGE — le seul endroit de la console qui déplace de l'argent.
     *
     * En faveur du CLIENT   : remboursement, via SequestreService.
     * En faveur de la BOUTIQUE : libération des fonds gelés, via le même
     * service.
     *
     * Aucune écriture n'est composée ici : c'est le service qui écrit au
     * grand livre, exactement comme pour la libération automatique. Deux
     * chemins pour la même opération finiraient par diverger, et l'écart
     * ne se verrait qu'à l'arrêté de cantonnement.
     */
    public function arbitrerLitige(Request $r, Litige $litige, LitigeService $litiges): JsonResponse
    {
        if (in_array($litige->statut, ['resolu_client', 'resolu_boutique', 'clos'], true)) {
            return response()->json([
                'message' => "Ce litige est déjà « {$litige->statut} » : il ne peut plus être arbitré.",
            ], 422);
        }

        $donnees = $r->validate([
            'sens'       => ['required', 'in:client,boutique'],
            'resolution' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $sc = $litige->sousCommande;

        try {
            DB::transaction(function () use ($litige, $sc, $donnees, $r) {
                // Le litige est clos AVANT de toucher à l'argent : tant qu'il
                // est « ouvert », SequestreService refuse — à juste titre — de
                // libérer les fonds. Dans l'ordre inverse, un arbitrage en
                // faveur de la boutique échouait systématiquement. Si l'argent
                // ne peut pas bouger, la transaction annule aussi la clôture.
                $litige->update([
                    'statut'        => $donnees['sens'] === 'client' ? 'resolu_client' : 'resolu_boutique',
                    'resolution'    => $donnees['resolution'],
                    'traite_par_id' => $r->user()->id,
                    'resolu_le'     => now(),
                ]);

                if ($donnees['sens'] === 'client') {
                    $this->sequestre->rembourser($sc, $donnees['resolution'], $r->user()->id);
                } else {
                    $this->sequestre->liberer($sc, 'arbitrage');
                }

                // Le journal de la sous-commande doit porter la trace de
                // l'arbitrage : c'est la pièce à produire si l'une des deux
                // parties revient dessus.
                $sc->journaliser('litige_arbitre', [
                    'litige'     => $litige->reference,
                    'sens'       => $donnees['sens'],
                    'resolution' => $donnees['resolution'],
                ], $r->user()->id, 'admin');
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Après la transaction, jamais dedans : un SMS ne se rattrape pas
        // si l'arbitrage est finalement annulé.
        $litiges->prevenirClientDeLaDecision($litige->fresh());

        return response()->json(new LitigeResource($litige->fresh(['sousCommande', 'photos'])));
    }

    /**
     * Clôture d'un retour à la place de la boutique — quand elle ne le
     * fait pas alors que le colis est revenu (constat du support, photo,
     * appel). Motif obligatoire : c'est un remboursement.
     */
    public function cloturerRetour(Request $r, SousCommande $sousCommande): JsonResponse
    {
        $donnees = $r->validate([
            'motif' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        try {
            $this->sequestre->cloturerRetourColis($sousCommande, $donnees['motif'], $r->user()->id, 'admin');
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Retour clôturé : client remboursé, stock rendu.']);
    }

    /**
     * Ouverture d'un litige POUR LE COMPTE du client, quand il a appelé
     * le support plutôt que d'utiliser sa page de commande. Possible
     * même après sa confirmation ou la fin du délai, tant que l'argent
     * est encore en séquestre (voir LitigeService::ouvrir()).
     */
    public function ouvrirLitige(Request $r, SousCommande $sousCommande, LitigeService $litiges): JsonResponse
    {
        $donnees = $r->validate([
            'motif'       => ['required', Rule::in(Litige::MOTIFS)],
            'description' => ['required', 'string', 'min:20', 'max:2000'],
        ]);

        try {
            $litige = $litiges->ouvrir($sousCommande, $donnees['motif'], $donnees['description'], 'admin', $r->user()->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new LitigeResource($litige->load('sousCommande')), 201);
    }

    // -----------------------------------------------------------------
    //  CODES DE REMISE — livraison à domicile ou retrait boutique
    // -----------------------------------------------------------------
    //  Le code n'est JAMAIS exposé au vendeur ni au livreur (voir le
    //  commentaire d'ExpeditionResource) : celui qui doit prouver la
    //  remise ne doit pas connaître la réponse à l'avance. L'admin est
    //  un tiers neutre — lui donner le code n'affaiblit pas ce contrôle
    //  — mais l'accès reste sensible : d'où le motif obligatoire et la
    //  double trace (historique de la sous-commande + journal
    //  d'administration, avec IP hachée).
    // -----------------------------------------------------------------

    /**
     * Consultation du code, pour un client injoignable qui a perdu son
     * téléphone ou pour arbitrer un litige. Ne modifie rien : seule la
     * consultation est journalisée.
     */
    public function codeRemise(Request $r, SousCommande $sousCommande): JsonResponse
    {
        $donnees = $r->validate([
            'motif' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        $sousCommande->load(['expedition', 'commande']);
        $modeLivraison = $sousCommande->commande->mode_livraison;
        $expedition = $sousCommande->expedition;

        $code = $modeLivraison === 'retrait_boutique'
            ? $sousCommande->code_retrait
            : $expedition?->code_livraison;

        if ($code === null) {
            return response()->json([
                'message' => 'Aucun code n’a encore été généré pour cette sous-commande.',
            ], 422);
        }

        $this->journaliserAccesCode($r, $sousCommande, 'code_consulte', $donnees['motif']);

        return response()->json([
            'mode_livraison' => $modeLivraison,
            'code'           => $code,
            'deja_valide'    => $modeLivraison === 'retrait_boutique'
                ? $sousCommande->statut === 'livree'
                : $expedition?->statut === 'livree',
            'tentatives'     => $modeLivraison === 'retrait_boutique'
                ? $sousCommande->retrait_tentatives
                : $expedition?->tentatives,
        ]);
    }

    /**
     * Renvoi du SMS — TOUJOURS le code déjà généré, jamais un nouveau,
     * et toujours vers le téléphone de la commande (CodeRemiseService,
     * le même chemin que le bouton du client).
     */
    public function renvoyerCodeRemise(Request $r, SousCommande $sousCommande, CodeRemiseService $codes): JsonResponse
    {
        $donnees = $r->validate([
            'motif' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        try {
            $codes->renvoyer($sousCommande);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->journaliserAccesCode($r, $sousCommande, 'code_renvoye', $donnees['motif']);

        return response()->json(['message' => 'Le code a été renvoyé par SMS.']);
    }

    /**
     * Double trace pour tout accès à un code de remise : l'historique de
     * la sous-commande (visible au même endroit que le reste de son
     * suivi) ET le journal d'administration (audit de sécurité —
     * `journal_administration` existait depuis la conformité RGPD mais
     * n'était encore alimenté par personne). Un accès à un code n'est
     * jamais anodin : il faut pouvoir répondre après coup à « qui a vu
     * quel code, quand, et pourquoi ».
     */
    private function journaliserAccesCode(Request $r, SousCommande $sousCommande, string $type, string $motif): void
    {
        $sousCommande->journaliser($type, ['motif' => $motif], $r->user()->id, 'admin');

        JournalAdministration::create([
            'agent_id'   => $r->user()->id,
            'action'     => $type,
            'cible_type' => 'sous_commande',
            'cible_id'   => $sousCommande->id,
            'motif'      => $motif,
            'ip_hachee'  => hash('sha256', $r->ip()),
        ]);
    }

    // -----------------------------------------------------------------
    //  MODÉRATION DU CATALOGUE
    // -----------------------------------------------------------------

    /**
     * Les fiches produit qui attendent une décision.
     *
     * CE CHAÎNON MANQUAIT, et son absence rendait le catalogue
     * inutilisable : `VendeurController::creerProduit()` laisse
     * volontairement `statut_moderation` à « en_attente » (défaut de la
     * table), et rien nulle part ne permettait de passer à « publie ».
     * Un vendeur pouvait donc créer une fiche que personne, jamais, ne
     * pouvait rendre visible. Le tableau de bord comptait même les
     * fiches à modérer — sans offrir d'écran pour les modérer.
     */
    public function produits(Request $r): JsonResponse
    {
        $requete = Produit::query()
            ->with(['boutique:id,nom,emoji,code', 'categorie:id,nom,emoji', 'variantes', 'medias']);

        // Par défaut, la file d'attente. Une console qui s'ouvre sur le
        // catalogue entier fait chercher le travail du jour.
        $requete->whereIn('statut_moderation', (array) $r->input('statut', ['en_attente']));

        if ($r->filled('recherche')) {
            $requete->where('nom', 'like', '%' . $r->string('recherche') . '%');
        }

        return response()->json(
            ProduitResource::collection(
                // Le plus ancien en premier : c'est celui qui fait le
                // plus de mal en restant là.
                $requete->orderBy('cree_le')->paginate($r->integer('par_page', 25))
            )->response()->getData(true)
        );
    }

    /**
     * Décision de modération sur une fiche.
     *
     * Les quatre décisions possibles sont celles de l'énumération en
     * base, et pas une de plus :
     *
     *   publie  — la fiche devient visible en vitrine et achetable ;
     *   rejete  — refusée, le vendeur doit corriger et resoumettre ;
     *   retire  — était publiée, on la sort du catalogue ;
     *   en_attente — remise dans la file (annulation d'une décision).
     *
     * LE MOTIF EST OBLIGATOIRE DÈS QUE LA DÉCISION EST DÉFAVORABLE.
     * Un rejet muet est incontestable : le vendeur ne sait pas quoi
     * corriger, il resoumet à l'identique, et la file grossit. C'est la
     * même règle que pour le refus d'une candidature.
     */
    public function modererProduit(Request $r, Produit $produit): JsonResponse
    {
        $donnees = $r->validate([
            'decision' => ['required', 'in:publie,rejete,retire,en_attente'],
            'motif'    => ['nullable', 'string', 'max:2000'],
        ]);

        $defavorable = in_array($donnees['decision'], ['rejete', 'retire'], true);

        if ($defavorable && mb_strlen(trim((string) ($donnees['motif'] ?? ''))) < 10) {
            return response()->json([
                'message' => 'Un refus ou un retrait doit être motivé — au moins dix caractères. '
                           . "Le vendeur ne peut pas corriger ce qu'on ne lui dit pas.",
                'errors'  => ['motif' => ['Motif obligatoire pour cette décision.']],
            ], 422);
        }

        if ($produit->statut_moderation === $donnees['decision']) {
            return response()->json([
                'message' => "Cette fiche est déjà « {$donnees['decision']} ».",
            ], 422);
        }

        $produit->update([
            'statut_moderation' => $donnees['decision'],
            /*
             * `?? null` et non `?:` — `validate()` ne renvoie QUE les
             * clés effectivement présentes dans la requête. Une
             * publication sans commentaire n'envoie pas `motif`, et
             * `$donnees['motif']` levait alors « Undefined array key ».
             *
             * Sur une publication sans commentaire, l'ancien motif est
             * effacé : garder « photo illisible » sur une fiche corrigée
             * puis publiée induirait le vendeur en erreur.
             */
            'motif_moderation'  => ($donnees['motif'] ?? null) ?: null,
            'modere_par_id'     => $r->user()->id,
            'modere_le'         => now(),
        ]);

        return response()->json([
            'produit' => new ProduitResource($produit->fresh(['boutique', 'categorie', 'variantes', 'medias'])),
            'message' => match ($donnees['decision']) {
                'publie'     => 'Fiche publiée : elle est visible en vitrine et achetable.',
                'rejete'     => 'Fiche refusée. Le motif est visible par la boutique.',
                'retire'     => 'Fiche retirée du catalogue.',
                'en_attente' => 'Fiche remise dans la file de modération.',
            },
        ]);
    }

    // -----------------------------------------------------------------
    //  REVERSEMENTS
    // -----------------------------------------------------------------

    /**
     * Lecture seule, volontairement.
     *
     * Marquer un virement « payé » depuis une console reviendrait à
     * déclarer à la main qu'une somme est partie. Cette confirmation
     * doit venir du prestataire de paiement, par son API ou son
     * webhook — sinon le grand livre affirme un versement que la banque
     * n'a jamais exécuté, et l'écart n'apparaît qu'à l'arrêté de
     * cantonnement, des jours plus tard.
     */
    public function reversements(Request $r): JsonResponse
    {
        $requete = Reversement::query();

        if ($r->filled('statut')) {
            $requete->whereIn('statut', (array) $r->input('statut'));
        }

        return response()->json(
            ReversementResource::collection(
                $requete->orderByDesc('id')->paginate($r->integer('par_page', 25))
            )->response()->getData(true)
        );
    }

    // -----------------------------------------------------------------

    /** Code boutique lisible : BF-V003, CI-V012… */
    private function prochainCodeBoutique(int $paysId): string
    {
        $code = Pays::find($paysId)?->code_iso2 ?? 'XX';
        $prefixe = $code . '-V';

        $dernier = Boutique::where('code', 'like', $prefixe . '%')->max('code');
        $numero  = $dernier ? ((int) substr($dernier, strlen($prefixe))) + 1 : 1;

        return sprintf('%s%03d', $prefixe, $numero);
    }

    private function slugUnique(string $nom): string
    {
        $base = Str::slug($nom);
        $slug = $base;
        $n = 1;

        while (Boutique::where('slug', $slug)->exists()) {
            $slug = $base . '-' . ++$n;
        }

        return $slug;
    }
}
