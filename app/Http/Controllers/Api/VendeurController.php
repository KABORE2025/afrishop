<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AjouterMediaRequest;
use App\Http\Requests\CreerProduitRequest;
use App\Http\Requests\MajStockRequest;
use App\Http\Requests\ModifierProduitRequest;
use App\Http\Resources\MediaResource;
use App\Http\Resources\ProduitResource;
use App\Http\Resources\ReversementResource;
use App\Http\Resources\SousCommandeResource;
use App\Http\Resources\VarianteProduitResource;
use App\Models\AgentRemiseBoutique;
use App\Models\Boutique;
use App\Models\Expedition;
use App\Models\Media;
use App\Models\Produit;
use App\Models\Reversement;
use App\Models\SousCommande;
use App\Models\Utilisateur;
use App\Models\VarianteProduit;
use App\Services\MediaService;
use App\Services\NotificationService;
use App\Services\SequestreService;
use App\Services\TableauBordVendeurService;
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
    public function __construct(
        private NotificationService $notifications,
    ) {}

    /** Sous-commandes de la boutique de l'utilisateur connecté, et d'elle seule. */
    public function commandes(Request $r): JsonResponse
    {
        $boutiqueId = $r->user()?->boutique?->id;

        if (! $boutiqueId) {
            return response()->json(['message' => 'Aucune boutique rattachée à ce compte.'], 403);
        }

        $requete = SousCommande::query()
            ->where('boutique_id', $boutiqueId)
            ->with(['lignes', 'expedition', 'commande:id,reference,cree_le,mode_livraison']);

        /*
         * Filtres — un vendeur cherche « ce que je dois préparer », pas
         * « toutes mes ventes depuis l'ouverture ». Sans eux, l'écran
         * devient inutilisable dès la centième commande.
         */
        if ($r->filled('statut')) {
            $requete->whereIn('statut', (array) $r->input('statut'));
        }

        if ($r->filled('etat_fonds')) {
            $requete->whereIn('etat_fonds', (array) $r->input('etat_fonds'));
        }

        if ($r->filled('recherche')) {
            $requete->where('reference', 'like', '%'.$r->string('recherche').'%');
        }

        return response()->json(
            SousCommandeResource::collection(
                $requete->orderByDesc('id')->paginate($r->integer('par_page', 25))
            )->response()->getData(true)
        );
    }

    /**
     * Les indicateurs de la boutique, calculés côté serveur.
     * La liste des commandes étant paginée, un total calculé depuis le
     * front porterait sur 25 lignes en croyant porter sur toutes.
     */
    public function tableauDeBord(Request $r, TableauBordVendeurService $service): JsonResponse
    {
        return response()->json($service->pour($this->resoudreBoutique($r)));
    }

    /** Livreurs actifs proposés au vendeur lors de l'affectation d'un colis. */
    public function livreurs(Request $r): JsonResponse
    {
        $boutique = $this->resoudreBoutique($r);
        if ($boutique->ville_id === null) {
            return response()->json(['data' => [], 'message' => 'La ville de la boutique doit être renseignée avant d’affecter un livreur.'], 422);
        }

        return response()->json(['data' => $boutique->agentsRemise()
            ->where('statut', 'actif')->where('autorise_livraison', true)
            ->whereHas('livreur', fn ($q) => $q->where('ville_id', $boutique->ville_id))
            ->with('livreur:id,nom,ville_id')->get()->map(fn (AgentRemiseBoutique $a) => [
                'id' => $a->livreur_id, 'nom' => $a->livreur->nom,
            ])->values()]);
    }

    /**
     * Passage à « expédiée ». Crée l'expédition (code de suivi, code de
     * livraison à usage unique).
     */
    public function expedier(Request $r, SousCommande $sousCommande): JsonResponse
    {
        $boutique = $this->resoudreBoutique($r, $sousCommande);

        if (! in_array($sousCommande->statut, ['a_preparer', 'prete'], true)) {
            return response()->json([
                'message' => "Cette sous-commande est « {$sousCommande->statut} » : elle ne peut plus être expédiée.",
            ], 422);
        }

        // Le retrait en boutique n'a pas de livreur : c'est preparerRetrait()
        // qui gère ce mode. Sans ce garde-fou, rien n'empêchait d'affecter
        // un livreur à une commande que le client vient chercher lui-même.
        if ($sousCommande->commande->mode_livraison === 'retrait_boutique') {
            return response()->json([
                'message' => 'Cette commande est en retrait boutique : utilisez « Préparer le retrait », pas l’expédition.',
            ], 422);
        }

        $data = $r->validate(['livreur_id' => ['required', 'integer']]);

        if ($boutique->ville_id === null) {
            return response()->json(['message' => 'La ville de la boutique doit être renseignée avant l’expédition.'], 422);
        }

        $autorise = AgentRemiseBoutique::where('boutique_id', $boutique->id)
            ->where('livreur_id', $data['livreur_id'])->where('statut', 'actif')
            ->where('autorise_livraison', true)
            ->whereHas('livreur', fn ($q) => $q->where('ville_id', $boutique->ville_id))->exists();
        if (! $autorise) {
            return response()->json(['message' => 'Cet agent n’est pas autorisé à livrer pour votre boutique.'], 422);
        }

        DB::transaction(function () use ($sousCommande, $data, $r) {
            $expedition = $sousCommande->expedition ?? new Expedition(['sous_commande_id' => $sousCommande->id]);
            $expedition->fill([
                'livreur_id' => $data['livreur_id'],
                // Généré une seule fois : ré-expédier ne doit pas changer
                // le code déjà communiqué au client par SMS.
                'code_livraison' => $expedition->code_livraison ?? (string) random_int(100000, 999999),
                'statut' => 'en_cours',
                'expedie_le' => now(),
            ]);
            $expedition->save();

            $sousCommande->update(['statut' => 'expediee', 'expedie_le' => now()]);
            $sousCommande->journaliser('expediee', [], $r->user()->id, 'vendeur');

            // Le SMS est mis en file dans la même transaction que l'expédition :
            // un code sans notification ne peut jamais être validé à la porte.
            $this->notifications->envoyer('code_livraison', 'sms', [
                'client_nom' => $sousCommande->commande->client_nom,
                'reference' => $sousCommande->reference,
                'code' => $expedition->code_livraison,
            ], telephone: $sousCommande->commande->client_telephone);

            // Le livreur aussi : sans ce message, la seule façon pour lui
            // de savoir qu'une course l'attend est d'ouvrir l'application
            // de son propre chef, ce qu'il n'a aucune raison de faire.
            $this->notifications->envoyer('nouvelle_livraison', 'sms', [
                'reference' => $sousCommande->reference,
                'quartier'  => $sousCommande->commande->quartier ?? 'destination à consulter',
            ], telephone: Utilisateur::find($data['livreur_id'])?->telephone);
        });

        return response()->json(new SousCommandeResource($sousCommande->fresh(['expedition', 'lignes'])));
    }

    /**
     * Passage à « prête » pour un retrait en boutique : génère le code à
     * usage unique et le fait parvenir au client. Aucune Expedition n'est
     * créée — il n'y a ni transporteur ni livreur, le client vient
     * lui-même chercher son colis au comptoir.
     */
    public function preparerRetrait(Request $r, SousCommande $sousCommande): JsonResponse
    {
        $this->resoudreBoutique($r, $sousCommande);
        $sousCommande->load('commande');

        if ($sousCommande->commande->mode_livraison !== 'retrait_boutique') {
            return response()->json(['message' => 'Cette commande n’est pas en retrait boutique.'], 422);
        }
        if ($sousCommande->statut !== 'a_preparer') {
            return response()->json([
                'message' => "Cette sous-commande est « {$sousCommande->statut} » : elle ne peut plus être préparée.",
            ], 422);
        }

        DB::transaction(function () use ($sousCommande, $r) {
            $code = (string) random_int(100000, 999999);

            $sousCommande->update([
                'statut' => 'prete',
                'code_retrait' => $code,
                'code_retrait_envoye_le' => now(),
            ]);
            $sousCommande->journaliser('prete_pour_retrait', [], $r->user()->id, 'vendeur');

            // Même garde que pour la livraison : le SMS part dans la même
            // transaction que le code, jamais l'un sans l'autre.
            $this->notifications->envoyer('code_retrait', 'sms', [
                'reference' => $sousCommande->reference,
                'code' => $code,
            ], telephone: $sousCommande->commande->client_telephone);
        });

        return response()->json(new SousCommandeResource($sousCommande->fresh('lignes')));
    }

    /**
     * Remise au comptoir : le vendeur saisit le code que le client lui
     * présente. Même logique de tentatives limitées que la livraison, pour
     * la même raison — un code n'a de valeur que s'il ne peut pas être
     * deviné par force brute.
     */
    public function confirmerRetrait(Request $r, SousCommande $sousCommande, SequestreService $sequestre): JsonResponse
    {
        $this->resoudreBoutique($r, $sousCommande);

        $data = $r->validate(['code_retrait' => ['required', 'digits:6']]);

        return DB::transaction(function () use ($r, $sousCommande, $data, $sequestre) {
            $sousCommande = SousCommande::whereKey($sousCommande->id)->lockForUpdate()->firstOrFail();

            if ($sousCommande->statut !== 'prete') {
                return response()->json(['message' => "Ce retrait n'est plus à valider."], 422);
            }
            if ($sousCommande->retrait_tentatives >= 5) {
                return response()->json(['message' => 'Code bloqué après 5 essais. Contactez le support Afrishop.'], 422);
            }
            if (! hash_equals((string) $sousCommande->code_retrait, $data['code_retrait'])) {
                $tentatives = $sousCommande->retrait_tentatives + 1;
                $sousCommande->update(['retrait_tentatives' => $tentatives]);
                $restantes = 5 - $tentatives;

                return response()->json(['message' => "Code incorrect. {$restantes} essai(s) restant(s)."], 422);
            }

            $sousCommande->update([
                'statut' => 'livree', 'livre_le' => now(), 'confirme_par_client_le' => now(),
            ]);
            $sousCommande->journaliser('retiree_en_boutique', [], $r->user()->id, 'vendeur');
            $sousCommande->commande->rafraichirStatut();

            /*
             * Le client vient de confirmer EN PERSONNE, en présentant le
             * code : c'est la preuve la plus forte que ce système connaît,
             * plus forte même qu'une confirmation à distance. Attendre le
             * balayage quotidien (`afrishop:liberer-fonds`) n'aurait aucun
             * sens ici — et ce balayage ne la trouverait de toute façon
             * jamais : sa requête ignore délibérément les sous-commandes
             * où `confirme_par_client_le` est déjà renseigné (voir
             * SequestreService::libererLesEchues()). Sans cet appel, une
             * commande retirée en boutique restait donc séquestrée pour
             * toujours.
             */
            $sousCommande->refresh();
            if ($sequestre->liberables($sousCommande)) {
                $sequestre->liberer($sousCommande, 'client');
            }

            return response()->json(new SousCommandeResource($sousCommande->fresh('lignes')));
        });
    }

    /** Produits de la boutique, avec leurs variantes. */
    public function produits(Request $r): JsonResponse
    {
        $boutique = $this->resoudreBoutique($r);

        return response()->json(
            ProduitResource::collection(
                Produit::where('boutique_id', $boutique->id)
                    ->with(['variantes', 'medias'])
                    ->orderByDesc('id')
                    ->paginate($r->integer('par_page', 25))
            )->response()->getData(true)
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
                'boutique_id' => $boutique->id,
                'categorie_id' => $data['categorie_id'],
                'reference' => Produit::prochaineReference($boutique),
                'nom' => $data['nom'],
                'slug' => Produit::slugUnique($data['nom']),
                'description' => $data['description'] ?? null,
                'prix_ttc_cfa' => $data['prix_ttc_cfa'],
                'poids_g' => $data['poids_g'] ?? null,
                'tracable' => $data['tracable'] ?? false,
            ]);

            $variantes = $data['variantes'] ?? [[
                'libelle' => 'Standard', 'stock' => 0, 'seuil_alerte' => 3,
            ]];

            foreach ($variantes as $i => $v) {
                $produit->variantes()->create([
                    'sku' => $produit->reference.'-V'.($i + 1),
                    'libelle' => $v['libelle'],
                    'prix_ttc_cfa' => $v['prix_ttc_cfa'] ?? null,
                    'stock' => $v['stock'],
                    'seuil_alerte' => $v['seuil_alerte'] ?? 3,
                    'defaut' => $i === 0,
                ]);
            }

            return $produit;
        });

        return response()->json(new ProduitResource($produit->fresh('variantes')), 201);
    }

    /** Historique des reversements de la boutique. */
    public function reversements(Request $r): JsonResponse
    {
        $boutique = $this->resoudreBoutique($r);

        return response()->json(
            ReversementResource::collection(
                Reversement::where('boutique_id', $boutique->id)
                    ->orderByDesc('id')
                    ->paginate($r->integer('par_page', 25))
            )->response()->getData(true)
        );
    }

    /**
     * Modification d'un produit existant.
     *
     * MANQUAIT COMPLÈTEMENT : un vendeur pouvait créer une fiche mais
     * jamais corriger un prix ni une description. Le seul recours était
     * d'en créer une seconde — d'où des catalogues en double.
     *
     * Ce qui n'est PAS modifiable ici (référence, slug, boutique,
     * statut de modération) est justifié dans ModifierProduitRequest.
     */
    public function modifierProduit(ModifierProduitRequest $r, Produit $produit): JsonResponse
    {
        $boutique = $this->resoudreBoutique($r);

        if ($produit->boutique_id !== $boutique->id) {
            abort(403, "Ce produit n'appartient pas à votre boutique.");
        }

        $donnees = $r->validated();

        /*
         * REPASSAGE EN MODÉRATION SUR CHANGEMENT DE FOND.
         * Corriger une faute de frappe dans le prix ne doit pas
         * remettre la fiche en file d'attente. Changer le nom, la
         * description ou la catégorie, si : sans cela, il suffirait de
         * publier « Miel de karité », d'attendre la validation, puis de
         * réécrire la fiche en produit interdit. Le contournement est
         * évident, la parade doit l'être aussi.
         */
        $champsSensibles = ['nom', 'description', 'categorie_id'];
        $remoderer = $produit->statut_moderation === 'publie'
            && collect($champsSensibles)->contains(
                fn ($c) => array_key_exists($c, $donnees) && $donnees[$c] !== $produit->$c
            );

        if ($remoderer) {
            $donnees['statut_moderation'] = 'en_attente';
            $donnees['motif_moderation'] = 'Fiche modifiée par la boutique — nouvelle validation requise.';
        }

        $produit->update($donnees);

        return response()->json([
            'produit' => new ProduitResource($produit->fresh('variantes')),
            'remodere' => $remoderer,
            'information' => $remoderer
                ? 'La fiche a été modifiée sur le fond : elle repasse en validation et n\'est plus visible en vitrine en attendant.'
                : null,
        ]);
    }

    /**
     * Mise à jour d'une variante — stock, prix, libellé.
     * Le geste le plus fréquent d'une boutique, et il n'existait pas.
     */
    public function majVariante(MajStockRequest $r, VarianteProduit $variante): JsonResponse
    {
        $boutique = $this->resoudreBoutique($r);

        if ($variante->produit->boutique_id !== $boutique->id) {
            abort(403, "Cette variante n'appartient pas à votre boutique.");
        }

        $donnees = $r->validated();

        return DB::transaction(function () use ($variante, $donnees) {
            /*
             * Le mouvement relatif est appliqué SOUS VERROU. Deux
             * réceptions saisies en même temps par deux personnes de la
             * même boutique doivent s'additionner ; sans le verrou, la
             * seconde écrase la première et le stock est faux sans que
             * rien ne le signale.
             */
            if (array_key_exists('mouvement', $donnees)) {
                $verrouillee = VarianteProduit::whereKey($variante->id)->lockForUpdate()->first();
                $donnees['stock'] = $verrouillee->stock + (int) $donnees['mouvement'];
                unset($donnees['mouvement']);
                $variante = $verrouillee;
            }

            $variante->update($donnees);
            $variante->refresh();

            return response()->json([
                'variante' => new VarianteProduitResource($variante),
                /* Une alerte lisible plutôt qu'un booléen que l'écran
                 * oubliera d'interpréter. */
                'alerte' => match (true) {
                    $variante->stock < 0 => 'Stock négatif : une survente a été constatée sur cette variante.',
                    $variante->enRupture() => 'Rupture de stock : la variante n\'est plus achetable.',
                    $variante->stockCritique() => 'Stock critique : '.$variante->stock.' unité(s) restante(s).',
                    default => null,
                },
            ]);
        });
    }

    /**
     * Ajoute une photo ou une vidéo à un produit.
     *
     * SUR CE MARCHÉ, LA PHOTO FAIT LA VENTE. Un produit sans image ne
     * se vend pas, quel que soit son prix — c'est le premier manque à
     * combler de l'espace vendeur.
     *
     * L'image est réduite en trois tailles à l'arrivée (1200 / 400 /
     * 100 px) : c'est ce qui rend le catalogue consultable sur un
     * forfait facturé au mégaoctet.
     */
    public function ajouterMedia(AjouterMediaRequest $r, Produit $produit, MediaService $medias): JsonResponse
    {
        $boutique = $this->resoudreBoutique($r);

        if ($produit->boutique_id !== $boutique->id) {
            abort(403, "Ce produit n'appartient pas à votre boutique.");
        }

        /*
         * Plafond par produit. Sans lui, rien n'empêche une boutique de
         * déposer quarante photos : le disque se remplit, et la fiche
         * devient illisible pour l'acheteur.
         */
        $existants = Media::where('proprietaire_type', 'produit')
            ->where('proprietaire_id', $produit->id)->count();

        if ($existants >= 8) {
            return response()->json([
                'message' => 'Huit médias au maximum par produit. Supprimez-en un avant d\'en ajouter.',
            ], 422);
        }

        $media = $r->input('type') === 'video'
            ? $medias->ajouterVideo($r->file('fichier'), $r->file('poster'),
                'produit', $produit->id, $r->input('texte_alternatif'))
            : $medias->ajouterImage($r->file('fichier'),
                'produit', $produit->id, $r->input('texte_alternatif'));

        return response()->json(new MediaResource($media), 201);
    }

    /** Suppression d'un média — fichiers compris. */
    public function supprimerMedia(Request $r, Media $media, MediaService $medias): JsonResponse
    {
        $boutique = $this->resoudreBoutique($r);

        // Le média ne porte pas de boutique_id : le cloisonnement passe
        // par le produit propriétaire, qu'il faut donc aller chercher.
        $produit = $media->proprietaire_type === 'produit'
            ? Produit::find($media->proprietaire_id)
            : null;

        if (! $produit || $produit->boutique_id !== $boutique->id) {
            abort(403, "Ce média n'appartient pas à votre boutique.");
        }

        $medias->supprimer($media);

        return response()->json(['message' => 'Média supprimé.']);
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
