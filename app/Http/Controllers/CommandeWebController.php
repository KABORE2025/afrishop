<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommanderWebRequest;
use App\Models\Commande;
use App\Models\Pays;
use App\Models\Ville;
use App\Services\Paiement\PaiementCommandeService;
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
        private PaiementCommandeService $paiement,
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
            'villesParPays' => $this->villesLivrables(),
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

        return $this->lancerPaiement($commande);
    }

    /**
     * =================================================================
     *  LES VILLES RÉELLEMENT LIVRABLES, GROUPÉES PAR PAYS
     * =================================================================
     *  DEUX DÉFAUTS CORRIGÉS ICI, ET ILS RENDAIENT LA COMMANDE
     *  IMPOSSIBLE.
     *
     *  1. Le formulaire proposait « — Autre ville — » (`ville_id` vide).
     *     Or `PanierService::calculerFrais()` cherche une zone de
     *     livraison, et AUCUNE zone du référentiel n'a `ville_id` à
     *     NULL. Choisir « autre ville » menait donc invariablement à
     *     « Aucune livraison n'est assurée à cette adresse ». Une
     *     option qui ne peut jamais aboutir n'a pas à exister.
     *
     *  2. La liste des villes n'était pas filtrée par pays : on pouvait
     *     choisir « Burkina Faso » et « Abidjan ». La zone existe pour
     *     Abidjan, mais rattachée à la Côte d'Ivoire — la requête ne
     *     trouvait rien, avec le même message incompréhensible.
     *
     *  La correction supprime le champ « Pays » : LE PAYS SE DÉDUIT DE
     *  LA VILLE. Un champ de moins, et deux valeurs qui ne peuvent plus
     *  se contredire. Les villes sont groupées par pays dans le menu,
     *  ce qui garde l'information à l'écran sans la faire ressaisir.
     *
     *  Seules les villes ayant une zone de livraison ACTIVE sont
     *  proposées : afficher une ville qu'on ne dessert pas, c'est
     *  laisser un client remplir tout le formulaire pour rien.
     * =================================================================
     */
    private function villesLivrables()
    {
        return Ville::query()
            ->where('villes.active', true)
            ->whereExists(fn ($q) => $q
                ->selectRaw(1)
                ->from('zones_livraison')
                ->whereColumn('zones_livraison.ville_id', 'villes.id')
                ->where('zones_livraison.active', true))
            ->join('pays', 'pays.id', '=', 'villes.pays_id')
            ->where('pays.ouvert_a_la_vente', true)
            ->orderBy('pays.nom')
            ->orderBy('villes.nom')
            ->get(['villes.id', 'villes.nom', 'villes.pays_id', 'pays.nom as pays_nom'])
            ->groupBy('pays_nom');
    }

    /**
     * =================================================================
     *  DÉMARRAGE DE L'ENCAISSEMENT
     * =================================================================
     *  CE MAILLON MANQUAIT, et son absence vidait de sens tout le reste.
     *  `CommandeController::creer()` (l'API) appelait bien
     *  `demarrerPaiement()` ; le tunnel web, lui, créait la commande et
     *  s'arrêtait là. La commande naissait donc en
     *  « attente_encaissement » et y restait pour toujours : aucun
     *  paiement n'était jamais demandé, aucune somme n'entrait, et le
     *  séquestre — la promesse centrale d'Afrishop — n'avait rien à
     *  retenir. Le stock, lui, était bien décrémenté.
     *
     *  Trois issues, et chacune mène quelque part :
     *
     *   reussie     le prestataire encaisse tout de suite (c'est ce que
     *               fait FakeGateway) → page de confirmation ;
     *   en_attente  un vrai PSP renvoie une URL où le client valide sur
     *               son téléphone → on l'y envoie ;
     *   echouee     refus immédiat. `PaiementCommandeService` a déjà
     *               remis le stock et annulé la commande → on le dit.
     * =================================================================
     */
    private function lancerPaiement(Commande $commande): RedirectResponse
    {
        try {
            $resultat = $this->paiement->demarrerPaiement($commande);
        } catch (\Throwable $e) {
            /*
             * La passerelle est injoignable. LA COMMANDE EXISTE DÉJÀ et
             * le stock est réservé : on ne la perd surtout pas, on
             * envoie le client sur sa confirmation, d'où il pourra
             * relancer le paiement. Le message technique reste dans le
             * journal ; le client lit une phrase qui lui dit quoi faire.
             */
            report($e);

            return redirect()->route('commande.confirmee', $commande->reference)
                ->with('erreur', "Le paiement n'a pas pu être lancé. Votre commande est "
                    .'enregistrée : réessayez depuis cette page.');
        }

        if ($resultat['statut'] === 'en_attente' && ! empty($resultat['url_paiement'])) {
            // `away()` et non `to()` : la destination est le site du
            // prestataire, hors de l'application.
            return redirect()->away($resultat['url_paiement']);
        }

        if ($resultat['statut'] === 'echouee') {
            return redirect()->route('commande.confirmee', $commande->reference)
                ->with('erreur', 'Le paiement a été refusé. Les articles ont été remis en stock.');
        }

        return redirect()->route('commande.confirmee', $commande->reference);
    }

    /**
     * Relance d'un paiement resté en attente ou échoué.
     *
     * Sans cette porte, un client dont le paiement a échoué — réseau
     * coupé, solde insuffisant au moment du prélèvement — n'avait aucun
     * moyen de réessayer : il devait refaire tout son panier. La
     * commande, elle, restait en base à consommer du stock.
     *
     * Aucune authentification : la référence suffit, comme pour la page
     * de confirmation. Le risque est mesuré — relancer le paiement de la
     * commande d'un inconnu ne fait que lui redemander SON argent, sur
     * SON téléphone.
     */
    public function payer(string $reference): RedirectResponse
    {
        $commande = Commande::where('reference', $reference)->firstOrFail();

        // Déjà encaissée : ne pas redemander l'argent. Le contrôle est
        // ici parce que rien n'empêche un client de rafraîchir la page
        // ou de rouvrir un vieux lien reçu par SMS.
        if ($commande->statut_paiement === 'encaisse') {
            return redirect()->route('commande.confirmee', $reference)
                ->with('succes', 'Cette commande est déjà payée.');
        }

        return $this->lancerPaiement($commande);
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
