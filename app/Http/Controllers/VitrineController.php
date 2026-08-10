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
            ->whereHas('boutique', fn ($b) => $b->where('statut', 'actif')->where('vend_en_ligne', true))
            // `with()` charge les relations en une seule requête. Sans lui,
            // 24 produits déclenchent 73 requêtes — invisible en local,
            // fatal dès que le catalogue grossit.
            ->with(['boutique:id,nom,emoji,slug', 'categorie:id,nom,emoji,slug', 'variantes'])
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
        $produit = Produit::query()
            ->where('slug', $slug)
            ->where('actif', true)
            ->with(['boutique', 'categorie', 'variantes'])
            ->firstOrFail();

        // Rapprochement sur le nom exact. Volontairement simple : imposer
        // un référentiel produit commun serait plus rigoureux, mais des
        // artisans ne le rempliraient jamais — et un comparateur vide ne
        // compare rien.
        $offres = Produit::query()
            ->where('id', '!=', $produit->id)
            ->where('nom', $produit->nom)
            ->where('actif', true)
            ->whereHas('boutique', fn ($b) => $b->where('statut', 'actif')->where('vend_en_ligne', true))
            ->with(['boutique:id,nom,emoji', 'variantes'])
            ->get();

        return view('produit', compact('produit', 'offres'));
    }
}
