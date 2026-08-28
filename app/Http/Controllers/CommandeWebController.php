<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommanderWebRequest;
use App\Models\Commande;
use App\Models\Pays;
use App\Models\Ville;
use App\Services\PanierService;
use App\Services\PanierSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use RuntimeException;

/**
 * =====================================================================
 *  TUNNEL DE COMMANDE — rendu serveur
 * =====================================================================
 *  Un seul formulaire, une seule page. Pas d'assistant en quatre
 *  étapes : chaque étape est un aller-retour réseau de plus, et sur une
 *  connexion instable, c'est quatre occasions d'échouer au lieu d'une.
 *  Le client remplit ce qu'il voit et valide.
 *
 *  LA CRÉATION DE LA COMMANDE N'EST PAS ÉCRITE ICI. Elle appelle
 *  PanierService::creerCommande(), exactement comme le fait l'API —
 *  verrou sur le stock, éclatement par boutique, calcul des frais,
 *  plafonds de paiement, écriture au grand livre. Réimplémenter tout
 *  cela pour le web donnerait deux chemins pour la même opération, et
 *  un jour ils divergeraient sur un détail d'argent.
 * =====================================================================
 */
class CommandeWebController extends Controller
{
    public function __construct(
        private PanierSession $panier,
        private PanierService $service,
    ) {}

    /** Le formulaire de commande. */
    public function formulaire(): View|RedirectResponse
    {
        if ($this->panier->estVide()) {
            return redirect()->route('panier');
        }

        $detail = $this->panier->detail();

        // Le panier a pu se vider tout seul entre-temps (rupture,
        // produit retiré). On renvoie au panier, où les alertes
        // expliquent pourquoi.
        if ($detail['lignes']->isEmpty()) {
            return redirect()->route('panier');
        }

        return view('commander', array_merge($detail, [
            'villes' => Ville::orderBy('nom')->get(['id', 'nom', 'pays_id']),
            'pays'   => Pays::orderBy('nom')->get(['id', 'nom', 'code_iso2']),
        ]));
    }

    /**
     * Validation de la commande.
     *
     * `CommanderWebRequest` et non `CreerCommandeRequest` : sur le web,
     * les articles viennent de la session, pas du formulaire. La
     * validation échouait autrement sur « articles.required » — donc
     * « Le panier est vide. » alors qu'il était plein.
     */
    public function enregistrer(CommanderWebRequest $r): RedirectResponse
    {
        $articles = $this->panier->articlesPourCommande();

        if ($articles === []) {
            return redirect()->route('panier')->with('erreur', 'Votre panier est vide.');
        }

        try {
            $commande = $this->service->creerCommande(
                $articles,
                $r->safe()->except(['articles', 'pays_id']),
                Pays::findOrFail($r->validated('pays_id')),
                $r->user()?->id,
            );
        } catch (RuntimeException $e) {
            /*
             * PanierService lève une RuntimeException avec un message
             * déjà rédigé en français et destiné au client — « Stock
             * insuffisant pour « Miel de karité » : il en reste 2. »
             * On l'affiche tel quel plutôt que d'écrire une deuxième
             * formulation qui divergerait de la première.
             */
            return back()->withInput()->with('erreur', $e->getMessage());
        }

        /*
         * Le panier n'est vidé QU'APRÈS la création réussie. Le vider
         * avant, ou dans un `finally`, ferait perdre son panier à un
         * client dont la commande a échoué pour une rupture de stock —
         * et il ne reviendrait pas le refaire.
         */
        $this->panier->vider();

        return redirect()->route('commande.confirmee', $commande->reference);
    }

    /**
     * Confirmation.
     *
     * La référence suffit à afficher la page : c'est délibéré. Un client
     * qui commande sans compte doit pouvoir revenir sur sa confirmation
     * depuis le lien reçu par SMS, sans se connecter. En contrepartie,
     * cette page ne montre RIEN de sensible — pas de numéro de
     * téléphone complet, pas d'adresse — parce qu'une référence peut
     * être devinée ou partagée.
     */
    public function confirmee(string $reference): View
    {
        $commande = Commande::where('reference', $reference)
            ->with(['sousCommandes.boutique', 'sousCommandes.lignes'])
            ->firstOrFail();

        return view('commande-confirmee', compact('commande'));
    }
}
