<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\Categorie;
use App\Models\Produit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * =====================================================================
 *  CATALOGUE — lecture publique
 * =====================================================================
 *  Ces quatre routes sont les seules du système qui ne demandent aucune
 *  authentification. Elles sont donc les plus appelées, et les seules
 *  qu'un robot d'indexation verra.
 *
 *  TROIS RÈGLES TENUES ICI, ET NULLE PART AILLEURS :
 *
 *  1. ON NE MONTRE QUE CE QUI EST VENDABLE. Boutique active, produit
 *     actif, et — pour une catégorie réservée — publication autorisée.
 *     Un oubli ici met en vitrine une fiche non relue.
 *
 *  2. ON NE FAIT JAMAIS DE REQUÊTE PAR PRODUIT. `with()` charge les
 *     relations en une fois. Sans lui, afficher 40 produits déclenche
 *     121 requêtes — la panne de performance la plus banale de Laravel,
 *     et elle ne se voit pas tant que le catalogue est petit.
 *
 *  3. LE PRIX AFFICHÉ EST CELUI DE LA VARIANTE LA MOINS CHÈRE, pas
 *     celui du produit. C'est la variante qui porte le prix et le stock.
 * =====================================================================
 */
class CatalogueController extends Controller
{
    /** Catégories visibles, dans l'ordre d'affichage voulu. */
    public function categories(): JsonResponse
    {
        return response()->json(
            Categorie::query()
                ->where('active', true)
                ->orderBy('ordre')
                ->get(['id', 'nom', 'slug', 'emoji', 'reservee', 'note_reserve'])
        );
    }

    /** Boutiques ouvertes à la vente en ligne. */
    public function boutiques(): JsonResponse
    {
        return response()->json(
            Boutique::query()
                ->where('statut', 'actif')
                ->where('vend_en_ligne', true)
                ->orderBy('nom')
                ->get(['id', 'nom', 'slug', 'emoji', 'description', 'ville_id', 'est_regie'])
        );
    }

    /**
     * Liste paginée du catalogue.
     *
     * La pagination n'est pas un confort : sans elle, la première
     * boutique qui charge 5 000 références fait tomber la page.
     */
    public function produits(Request $r): JsonResponse
    {
        $q = Produit::query()
            ->where('actif', true)
            ->whereHas('boutique', fn ($b) => $b->where('statut', 'actif')->where('vend_en_ligne', true))
            ->with([
                'boutique:id,nom,slug,emoji,est_regie',
                'categorie:id,nom,slug,emoji',
                'variantes' => fn ($v) => $v->where('actif', true)->orderBy('prix_ttc_cfa'),
            ]);

        if ($r->filled('categorie')) {
            $q->whereHas('categorie', fn ($c) => $c->where('slug', $r->string('categorie')));
        }
        if ($r->filled('boutique')) {
            $q->whereHas('boutique', fn ($b) => $b->where('slug', $r->string('boutique')));
        }
        if ($r->filled('q')) {
            $terme = '%' . $r->string('q') . '%';
            $q->where(fn ($w) => $w->where('nom', 'like', $terme)->orWhere('description', 'like', $terme));
        }

        return response()->json($q->paginate($r->integer('par_page', 24)));
    }

    /**
     * Le comparateur : qui d'autre vend ce produit, et à quel prix ?
     *
     * Le rapprochement se fait sur le nom normalisé, volontairement.
     * Imposer un référentiel produit commun serait plus rigoureux, mais
     * des artisans ne le rempliraient jamais correctement — et un
     * comparateur vide ne compare rien.
     */
    public function offres(Produit $produit): JsonResponse
    {
        $offres = Produit::query()
            ->where('id', '!=', $produit->id)
            ->where('actif', true)
            ->where('nom', $produit->nom)
            ->whereHas('boutique', fn ($b) => $b->where('statut', 'actif')->where('vend_en_ligne', true))
            ->with(['boutique:id,nom,slug,emoji', 'variantes:id,produit_id,prix_ttc_cfa,stock'])
            ->get();

        return response()->json([
            'produit' => $produit->only(['id', 'nom', 'slug']),
            'offres'  => $offres,
        ]);
    }
}
