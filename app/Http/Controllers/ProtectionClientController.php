<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\VerifieTelephoneCommande;
use App\Models\Commande;
use App\Models\Litige;
use App\Models\SousCommande;
use App\Services\LitigeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * =====================================================================
 *  FENÊTRE DE PROTECTION — les deux gestes du client après la remise
 * =====================================================================
 *  « Tout est conforme » (les fonds partent vers la boutique) ou
 *  « Signaler un problème » (les fonds sont gelés jusqu'à l'arbitrage).
 *
 *  La page de commande s'ouvre avec la seule référence, qui peut être
 *  partagée ou devinée. Ces deux gestes, eux, déplacent ou gèlent de
 *  l'argent : ils exigent en plus le téléphone de la commande, comme
 *  le suivi (`/api/commandes/suivi`). Sans cela, n'importe qui
 *  connaissant une référence pourrait geler le paiement d'une boutique.
 * =====================================================================
 */
class ProtectionClientController extends Controller
{
    use VerifieTelephoneCommande;

    public function __construct(private LitigeService $litiges) {}

    public function signaler(Request $r, string $reference, string $colis): RedirectResponse
    {
        $donnees = $r->validate([
            'telephone'   => ['nullable', 'string', 'max:25'],
            'motif'       => ['required', Rule::in(Litige::MOTIFS)],
            'description' => ['required', 'string', 'min:20', 'max:2000'],
            // Facultatives : sur un téléphone modeste ou un réseau faible,
            // exiger une photo empêcherait certains clients de signaler.
            'photos'      => ['nullable', 'array', 'max:'.Litige::PHOTOS_MAX],
            'photos.*'    => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ], [
            'description.min' => 'Décrivez le problème en quelques phrases (20 caractères au moins) : c\'est ce que lira la personne qui tranchera.',
            'photos.max'      => 'Deux photos au plus.',
            'photos.*.image'  => 'Chaque fichier joint doit être une photo.',
            'photos.*.mimes'  => 'Photos acceptées : JPG, PNG ou WEBP.',
            'photos.*.max'    => 'Une photo dépasse 8 Mo : reprenez-la en qualité normale.',
        ]);

        $sc = $this->colisVerifie($r, $reference, $colis);
        if (! $sc) {
            return $this->refus($reference);
        }

        try {
            $litige = $this->litiges->ouvrir($sc, $donnees['motif'], $donnees['description'],
                photos: $r->file('photos', []));
        } catch (RuntimeException $e) {
            return back()->with('erreur', ' — '.$e->getMessage());
        }

        return redirect()->route('commande.confirmee', $reference)->with('succes',
            "Votre signalement {$litige->reference} est enregistré. Le paiement de la boutique est bloqué "
            .'jusqu\'à la décision d\'Afrishop ; la boutique va donner sa version, puis nous vous recontacterons.');
    }

    public function confirmer(Request $r, string $reference, string $colis): RedirectResponse
    {
        $r->validate(['telephone' => ['nullable', 'string', 'max:25']]);

        $sc = $this->colisVerifie($r, $reference, $colis);
        if (! $sc) {
            return $this->refus($reference);
        }

        try {
            $this->litiges->confirmerReception($sc);
        } catch (RuntimeException $e) {
            return back()->with('erreur', ' — '.$e->getMessage());
        }

        return redirect()->route('commande.confirmee', $reference)
            ->with('succes', 'Merci ! Vous avez confirmé la bonne réception : la boutique va être payée.');
    }

    /** Le colis, s'il appartient bien à cette commande ET au client (téléphone ou session). */
    private function colisVerifie(Request $r, string $reference, string $colis): ?SousCommande
    {
        $commande = Commande::where('reference', $reference)->first();

        if (! $commande || ! $this->telephoneVerifie($r, $commande)) {
            return null;
        }

        return $commande->sousCommandes()->where('reference', $colis)->first();
    }

    private function refus(string $reference): RedirectResponse
    {
        // Même message que la référence soit bonne ou non : distinguer
        // les cas permettrait de deviner le téléphone d'un client.
        return redirect()->route('commande.confirmee', $reference)->withInput()
            ->with('erreur', ' — ce numéro de téléphone ne correspond pas à cette commande.');
    }
}
