<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\Sms\EnvoiSmsService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;

/**
 * =====================================================================
 *  BOÎTE SMS DE TEST — /dev/sms
 * =====================================================================
 *  Avec SMS_DRIVER=journal, aucun SMS ne part : cette page montre ce
 *  qui AURAIT été envoyé (codes de livraison, de retrait, d'activation,
 *  litiges…) pour dérouler un parcours complet sans opérateur.
 *
 *  ⚠ ELLE AFFICHE DES CODES QUI LIBÈRENT L'ARGENT DES BOUTIQUES.
 *  Trois verrous, cumulatifs :
 *    1. en production, les routes ne sont même pas déclarées (web.php) ;
 *    2. ailleurs, il faut APP_ENV=local ET SMS_DRIVER=journal — dès
 *       qu'un vrai opérateur est branché, la page disparaît ;
 *    3. seulement depuis la machine elle-même, et jamais à travers un
 *       tunnel (cloudflared, ngrok…) : un tunnel fait arriver les
 *       requêtes d'internet comme si elles venaient de 127.0.0.1, ce
 *       qui publierait la page sans que rien ne le signale.
 *  Si l'un manque : 404, comme si la page n'existait pas.
 * =====================================================================
 */
class BoiteSmsTestController extends Controller
{
    /** En-têtes posés par les tunnels et proxys : la requête vient d'ailleurs. */
    private const ENTETES_PROXY = ['CF-Connecting-IP', 'CF-Ray', 'X-Forwarded-For', 'X-Forwarded-Host', 'X-Real-IP'];

    public function index(Request $r): View
    {
        $this->garde($r);

        $telephone = preg_replace('/\D/', '', (string) $r->query('telephone'));

        $sms = Notification::query()
            ->where('canal', 'sms')
            ->when($telephone !== '', fn ($q) => $q->where('telephone', 'like', "%{$telephone}%"))
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (Notification $n) => [
                'telephone' => $n->telephone,
                'statut'    => $n->statut,
                'cree_le'   => $n->cree_le,
                'texte'     => $this->texte($n),
            ]);

        return view('dev.boite-sms', [
            'sms'       => $sms,
            'telephone' => $telephone,
            'enFile'    => Notification::where('canal', 'sms')->where('statut', 'en_file')->count(),
        ]);
    }

    /** Même chemin que le planificateur : afrishop:envoyer-notifications. */
    public function envoyer(Request $r, EnvoiSmsService $service): RedirectResponse
    {
        $this->garde($r);

        $bilan = $service->drainer(100);

        return redirect()->route('dev.sms', $r->only('telephone'))
            ->with('succes', "{$bilan['traitees']} SMS traité(s).");
    }

    private function garde(Request $r): void
    {
        $locale = in_array($r->ip(), ['127.0.0.1', '::1'], true);
        $tunnel = collect(self::ENTETES_PROXY)->contains(fn ($h) => $r->headers->has($h));

        abort_unless(
            app()->environment('local')
            && config('afrishop.sms.driver') === 'journal'
            && $locale && ! $tunnel,
            404
        );
    }

    /** Texte en clair. Une ligne illisible ne doit pas masquer toutes les autres. */
    private function texte(Notification $n): string
    {
        $brut = $n->getRawOriginal('corps_envoye');

        try {
            return Crypt::decryptString((string) $brut);
        } catch (DecryptException) {
            // Ligne antérieure au chiffrement, ou APP_KEY changée depuis.
            return (string) $brut;
        }
    }
}
