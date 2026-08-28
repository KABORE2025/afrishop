<?php

namespace App\Http\Controllers;

use App\Models\VarianteProduit;
use App\Services\PanierSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * =====================================================================
 *  PANIER — pages serveur, zéro JavaScript
 * =====================================================================
 *  Chaque action est un formulaire POST suivi d'une redirection. Ce
 *  schéma (POST puis redirection) évite qu'un rafraîchissement de page
 *  rejoue l'ajout : sans lui, un client qui appuie sur F5 après avoir
 *  ajouté un article en a deux.
 * =====================================================================
 */
class PanierController extends Controller
{
    public function __construct(private PanierSession $panier) {}

    public function voir(): View
    {
        return view('panier', $this->panier->detail());
    }

    public function ajouter(Request $r): RedirectResponse
    {
        $donnees = $r->validate([
            'variante_id' => ['required', 'integer', 'exists:variantes_produit,id'],
            'quantite'    => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $variante = VarianteProduit::with('produit.boutique')->find($donnees['variante_id']);

        /*
         * Vérifié ICI et re-vérifié à la création de commande. Ce n'est
         * pas de la redondance : entre la mise au panier et le paiement,
         * il peut se passer une heure, et le stock bouge. Le contrôle
         * d'ici sert à répondre tout de suite ; celui de PanierService,
         * sous verrou, est celui qui fait autorité.
         */
        if (! $variante->actif || ! $variante->produit->actif
            || $variante->produit->statut_moderation !== 'publie') {
            return back()->with('erreur', "Cet article n'est plus en vente.");
        }

        if ($variante->stock <= 0) {
            return back()->with('erreur', "« {$variante->produit->nom} » est en rupture de stock.");
        }

        /*
         * LA BOUTIQUE PEUT-ELLE ENCAISSER ?
         *
         * Contrôlé ICI, à la mise au panier, et pas seulement à la
         * validation. `PanierService` refusait bien la commande, mais
         * tout à la fin du tunnel — le client avait déjà saisi son nom,
         * son téléphone, son quartier et son repère. Faire perdre cinq
         * minutes pour annoncer un refus connu dès le premier clic est
         * la façon la plus sûre de ne jamais revoir l'acheteur.
         *
         * Le message vient de la boutique elle-même : congés, boutique
         * suspendue, compte de reversement pas encore vérifié — chacun
         * appelle une phrase différente, et « ne peut pas recevoir de
         * commande actuellement » n'en dit aucune.
         */
        if ($raison = $variante->produit->boutique->raisonIndisponibilite()) {
            return back()->with('erreur', $raison);
        }

        $this->panier->ajouter($donnees['variante_id'], $donnees['quantite'] ?? 1);

        return redirect()->route('panier')
            ->with('succes', "« {$variante->produit->nom} » a été ajouté au panier.");
    }

    public function modifier(Request $r): RedirectResponse
    {
        $donnees = $r->validate([
            'variante_id' => ['required', 'integer'],
            'quantite'    => ['required', 'integer', 'min:0', 'max:99'],
        ]);

        $this->panier->definir($donnees['variante_id'], $donnees['quantite']);

        return redirect()->route('panier');
    }

    public function vider(): RedirectResponse
    {
        $this->panier->vider();

        return redirect()->route('panier')->with('succes', 'Panier vidé.');
    }
}
