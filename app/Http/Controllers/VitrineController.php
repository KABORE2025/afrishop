<?php

namespace App\Http\Controllers;

use App\Models\Categorie;
use App\Models\Produit;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * =====================================================================
 *  VITRINE — pages rendues par le serveur
 * =====================================================================
 *  Pourquoi Blade et non une application JavaScript : le besoin BO-01 du
 *  dossier fixe la première page utile à moins de 3 secondes en 2G, sous
 *  300 Ko. Une application JavaScript doit charger son code avant de
 *  pouvoir afficher quoi que ce soit — elle échoue précisément dans les
 *  conditions où ce site doit marcher.
 *
 *  L'API JSON reste en place et garde son utilité : une application
 *  mobile, ou un partenaire qui voudrait consulter le catalogue.
 * =====================================================================
 */
class VitrineController extends Controller
{
    /** Catalogue paginé, filtrable par catégorie. */
    public function index(Request $r): View
    {
        $categorieActive = $r->string('categorie')->toString() ?: null;

        $produits = Produit::query()
            ->where('actif', true)
            /*
             * LA MÊME RÈGLE QUE LE PANIER, ET C'EST LE POINT.
             *
             * Ce filtre manquait. La vitrine listait tout produit actif,
             * y compris ceux qui attendaient encore une validation ; le
             * panier, lui, exige `statut_moderation = publie`. Résultat :
             * le catalogue affichait des articles que la mise au panier
             * refusait ensuite avec « Cet article n'est plus en vente. »
             *
             * Un client ne peut pas comprendre ce message : le produit
             * est sous ses yeux. La règle d'achetabilité doit être la
             * même partout — ici, dans PanierController::ajouter() et
             * dans PanierService::creerCommande().
             */
            ->where('statut_moderation', 'publie')
            /*
             * `peutVendre()` et non plus `statut = actif` seulement.
             *
             * Une boutique active mais sans compte de reversement
             * vérifié, ou en congés, ne peut PAS recevoir de commande —
             * `PanierService` la refuse. Le catalogue affichait pourtant
             * ses produits, et le client ne l'apprenait qu'à la
             * validation, après avoir saisi nom, téléphone et adresse.
             * Même défaut que la modération : deux règles différentes
             * pour la même question.
             */
            ->whereHas('boutique', fn ($b) => $b->peutVendre()->where('vend_en_ligne', true))
            // `with()` charge les relations en une seule requête. Sans lui,
            // 24 produits déclenchent 73 requêtes — invisible en local,
            // fatal dès que le catalogue grossit.
            ->with(['boutique:id,nom,emoji,slug', 'categorie:id,nom,emoji,slug', 'variantes', 'medias'])
            ->when($categorieActive, fn ($q, $slug) =>
                $q->whereHas('categorie', fn ($c) => $c->where('slug', $slug)))
            ->orderBy('nom')
            ->paginate(24)
            ->withQueryString();   // sinon le filtre se perd page 2

        return view('vitrine', [
            'produits'        => $produits,
            'categories'      => Categorie::where('active', true)->orderBy('ordre')->get(),
            'categorieActive' => $categorieActive,
        ]);
    }

    /** Fiche produit, avec les offres concurrentes. */
    public function produit(string $slug): View
    {
        // Même règle qu'en liste : une fiche non publiée ne doit pas
        // être atteignable par son URL, sinon il suffit de connaître le
        // lien pour contourner la modération.
        $produit = Produit::query()
            ->where('slug', $slug)
            ->where('actif', true)
            ->where('statut_moderation', 'publie')
            ->with(['boutique', 'categorie', 'variantes', 'medias'])
            ->firstOrFail();

        // Rapprochement sur le nom exact. Volontairement simple : imposer
        // un référentiel produit commun serait plus rigoureux, mais des
        // artisans ne le rempliraient jamais — et un comparateur vide ne
        // compare rien.
        $offres = Produit::query()
            ->where('id', '!=', $produit->id)
            ->where('nom', $produit->nom)
            ->where('actif', true)
            ->where('statut_moderation', 'publie')
            ->whereHas('boutique', fn ($b) => $b->peutVendre()->where('vend_en_ligne', true))
            ->with(['boutique:id,nom,emoji', 'variantes', 'medias'])
            ->get();

        /*
         * LA FICHE RESTE ACCESSIBLE MÊME SI LA BOUTIQUE NE PEUT PAS
         * VENDRE, et c'est délibéré. Un client qui a mis le lien en
         * favori, ou qui l'a reçu par WhatsApp, ne doit pas tomber sur
         * une erreur 404 parce que le vendeur est en congés trois
         * jours : il doit lire pourquoi, et quand revenir.
         *
         * La fiche disparaît en revanche des LISTES, où afficher un
         * produit non commandable ne fait perdre du temps à personne.
         */
        $indisponible = $produit->boutique->raisonIndisponibilite();

        // Avis d'acheteurs réels (colis remis), les plus récents d'abord.
        $avis = \App\Models\Avis::where('produit_id', $produit->id)->where('statut', 'publie')
            ->orderByDesc('id')->limit(20)->get();
        $noteMoyenne = $avis->isNotEmpty()
            ? round(\App\Models\Avis::where('produit_id', $produit->id)->where('statut', 'publie')->avg('note'), 1)
            : null;
        $nbAvis = $avis->isNotEmpty()
            ? \App\Models\Avis::where('produit_id', $produit->id)->where('statut', 'publie')->count()
            : 0;

        return view('produit', compact('produit', 'offres', 'indisponible', 'avis', 'noteMoyenne', 'nbAvis'));
    }
}
