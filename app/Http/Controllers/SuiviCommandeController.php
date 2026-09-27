<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\VerifieTelephoneCommande;
use App\Models\Commande;
use App\Services\CodeRemiseService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * =====================================================================
 *  SUIVI DE COMMANDE CÔTÉ CLIENT — sans compte
 * =====================================================================
 *  · /suivi : numéro de commande + téléphone → page de la commande ;
 *  · renvoi du code de remise (le même code, vers le téléphone de la
 *    commande, 3 fois par jour et par colis au plus) ;
 *  · reçu PDF, une fois le paiement encaissé.
 *
 *  Chaque geste exige la preuve du téléphone (VerifieTelephoneCommande),
 *  retenue en session après la première fois.
 * =====================================================================
 */
class SuiviCommandeController extends Controller
{
    use VerifieTelephoneCommande;

    /** Renvois de code par colis et par 24 h — chaque SMS se paie. */
    private const RENVOIS_PAR_JOUR = 3;

    public function formulaire(): View
    {
        return view('suivi');
    }

    public function rechercher(Request $r): RedirectResponse
    {
        $donnees = $r->validate([
            'reference' => ['required', 'string', 'max:40'],
            'telephone' => ['required', 'string', 'max:25'],
        ], [], ['reference' => 'numéro de commande', 'telephone' => 'téléphone']);

        $commande = Commande::where('reference', Str::upper(trim($donnees['reference'])))->first();

        // Même message dans les deux cas : distinguer « référence
        // inconnue » de « téléphone faux » permettrait d'énumérer les
        // références valides.
        if (! $commande || ! $this->telephoneVerifie($r, $commande)) {
            return back()->withInput()->with('erreur',
                ' — aucune commande ne correspond à ce numéro et à ce téléphone. '
                .'Vérifiez le numéro reçu par SMS (il commence par BF-CMD-).');
        }

        return redirect()->route('commande.confirmee', $commande->reference);
    }

    public function renvoyerCode(Request $r, string $reference, string $colis, CodeRemiseService $codes): RedirectResponse
    {
        $commande = Commande::where('reference', $reference)->firstOrFail();

        if (! $this->telephoneVerifie($r, $commande)) {
            return $this->refusTelephone($reference);
        }

        $sc = $commande->sousCommandes()->where('reference', $colis)->firstOrFail();
        $cle = 'renvoi-code:'.$sc->id;

        if (RateLimiter::tooManyAttempts($cle, self::RENVOIS_PAR_JOUR)) {
            $heures = max(1, (int) ceil(RateLimiter::availableIn($cle) / 3600));

            return back()->with('erreur', " — le code a déjà été renvoyé ".self::RENVOIS_PAR_JOUR." fois aujourd'hui. "
                ."Réessayez dans {$heures} h, ou appelez le service client Afrishop au ".telephone_support().'.');
        }

        try {
            $codes->renvoyer($sc);
        } catch (RuntimeException $e) {
            return back()->with('erreur', ' — '.$e->getMessage());
        }

        RateLimiter::hit($cle, 86400);
        $sc->journaliser('code_renvoye', ['demande_par' => 'client'], auteurType: 'client');

        return redirect()->route('commande.confirmee', $reference)->with('succes',
            'Votre code a été renvoyé par SMS au numéro de la commande. Il peut mettre quelques minutes à arriver.');
    }

    public function recu(Request $r, string $reference): Response
    {
        $commande = Commande::where('reference', $reference)
            ->with(['sousCommandes.boutique', 'sousCommandes.lignes', 'transactions'])
            ->firstOrFail();

        if (! $this->telephoneVerifie($r, $commande)) {
            return $this->refusTelephone($reference);
        }

        // Pas de reçu pour un paiement non encaissé : ce serait un
        // document attestant d'un paiement qui n'a pas eu lieu.
        if ($commande->statut_paiement !== 'encaisse') {
            return redirect()->route('commande.confirmee', $reference)
                ->with('erreur', ' — le reçu est disponible une fois le paiement confirmé.');
        }

        // Sous-ensemble de police : seuls les caractères utilisés sont
        // embarqués. Sans cela, dompdf joint la police DejaVu entière et
        // un reçu d'une page pèse ~880 Ko au lieu de ~26 Ko — payés en
        // data par le client.
        return Pdf::setOption(['enable_font_subsetting' => true])
            ->loadView('pdf.recu-commande', ['commande' => $commande])
            ->setPaper('a4')
            ->download("recu-{$commande->reference}.pdf");
    }

    private function refusTelephone(string $reference): RedirectResponse
    {
        return redirect()->route('commande.confirmee', $reference)->withInput()
            ->with('erreur', ' — ce numéro de téléphone ne correspond pas à cette commande.');
    }
}
