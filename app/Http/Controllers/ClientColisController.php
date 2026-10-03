<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\VerifieTelephoneCommande;
use App\Models\Avis;
use App\Models\Commande;
use App\Models\Retour;
use App\Models\SousCommande;
use App\Services\RetourService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * =====================================================================
 *  GESTES DU CLIENT SUR UN COLIS — annuler, retourner, noter
 * =====================================================================
 *  Même règle que les autres gestes de la page de commande : le
 *  téléphone de la commande est exigé, ou déjà prouvé en session
 *  (VerifieTelephoneCommande).
 * =====================================================================
 */
class ClientColisController extends Controller
{
    use VerifieTelephoneCommande;

    public function __construct(private RetourService $retours) {}

    public function annuler(Request $r, string $reference, string $colis): RedirectResponse
    {
        $sc = $this->colis($r, $reference, $colis);
        if (! $sc) {
            return $this->refus($reference);
        }

        try {
            $this->retours->annuler($sc);
        } catch (RuntimeException $e) {
            return back()->with('erreur', ' — '.$e->getMessage());
        }

        return redirect()->route('commande.confirmee', $reference)->with('succes',
            'Colis annulé. Le remboursement part sur le compte Mobile Money qui a servi à payer.');
    }

    public function retour(Request $r, string $reference, string $colis): RedirectResponse
    {
        $donnees = $r->validate([
            'motif'       => ['required', Rule::in(Retour::MOTIFS)],
            'commentaire' => ['nullable', 'string', 'max:1000'],
        ]);

        $sc = $this->colis($r, $reference, $colis);
        if (! $sc) {
            return $this->refus($reference);
        }

        try {
            $retour = $this->retours->demander($sc, $donnees['motif'], $donnees['commentaire'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('erreur', ' — '.$e->getMessage());
        }

        return redirect()->route('commande.confirmee', $reference)->with('succes',
            "Demande de retour {$retour->reference} envoyée à la boutique. Le paiement de la boutique est bloqué "
            .'en attendant. Vous serez remboursé quand elle aura reçu l’article.');
    }

    public function avis(Request $r, string $reference, string $colis): RedirectResponse
    {
        $donnees = $r->validate([
            'produit_id'  => ['required', 'integer'],
            'note'        => ['required', 'integer', 'min:1', 'max:5'],
            'commentaire' => ['nullable', 'string', 'max:1000'],
        ], ['note.required' => 'Choisissez une note de 1 à 5 étoiles.']);

        $sc = $this->colis($r, $reference, $colis);
        if (! $sc) {
            return $this->refus($reference);
        }

        // Seulement un colis REMIS, et seulement un produit qu'il contient.
        if ($sc->statut !== 'livree') {
            return back()->with('erreur', ' — vous pourrez donner votre avis une fois le colis reçu.');
        }
        $produitsDuColis = $sc->lignes()->with('variante:id,produit_id')->get()
            ->map(fn ($l) => $l->variante?->produit_id)->filter()->unique();
        if (! $produitsDuColis->contains((int) $donnees['produit_id'])) {
            return back()->with('erreur', ' — ce produit ne fait pas partie de ce colis.');
        }
        if (Avis::where('sous_commande_id', $sc->id)->where('produit_id', $donnees['produit_id'])->exists()) {
            return back()->with('erreur', ' — vous avez déjà donné votre avis sur ce produit.');
        }

        Avis::create([
            'sous_commande_id' => $sc->id,
            'produit_id'       => $donnees['produit_id'],
            'boutique_id'      => $sc->boutique_id,
            'auteur_affiche'   => Avis::auteurAffiche($sc->commande->client_nom),
            'note'             => $donnees['note'],
            'commentaire'      => $donnees['commentaire'] ?? null,
            // Publié tout de suite : seul un acheteur réel peut écrire. Un
            // administrateur peut retirer un avis abusif (console › Avis).
            'statut'           => 'publie',
        ]);

        return redirect()->route('commande.confirmee', $reference)->with('succes', 'Merci pour votre avis !');
    }

    private function colis(Request $r, string $reference, string $colis): ?SousCommande
    {
        $commande = Commande::where('reference', $reference)->first();
        if (! $commande || ! $this->telephoneVerifie($r, $commande)) {
            return null;
        }

        return $commande->sousCommandes()->with('commande')->where('reference', $colis)->first();
    }

    private function refus(string $reference): RedirectResponse
    {
        return redirect()->route('commande.confirmee', $reference)->withInput()
            ->with('erreur', ' — ce numéro de téléphone ne correspond pas à cette commande.');
    }
}
