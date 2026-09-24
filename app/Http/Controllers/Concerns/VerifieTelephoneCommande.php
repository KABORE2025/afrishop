<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Commande;
use Illuminate\Http\Request;

/**
 * Preuve qu'on est bien le client d'une commande : son téléphone.
 *
 * La page de commande s'ouvre avec la seule référence (partageable,
 * devinable). Tout ce qui agit ou révèle davantage — confirmer, signaler,
 * renvoyer le code, télécharger le reçu — exige en plus le téléphone.
 *
 * Une fois prouvé, c'est retenu en SESSION pour cette commande : le client
 * ne retape pas son numéro à chaque geste. La session est propre à son
 * navigateur ; quelqu'un d'autre qui ouvre le même lien doit prouver
 * à son tour.
 */
trait VerifieTelephoneCommande
{
    /** Au-delà, les plus anciennes sont oubliées : une session n'est pas un annuaire. */
    private int $maxCommandesVerifiees = 10;

    protected function telephoneVerifie(Request $r, Commande $commande): bool
    {
        if ($this->dejaVerifiee($r, $commande)) {
            return true;
        }

        $chiffres = fn (?string $t) => preg_replace('/\D/', '', (string) $t);
        $saisi = $chiffres($r->input('telephone'));

        // Comparaison sur la fin du numéro : le client le saisit souvent
        // sans l'indicatif pays. 8 chiffres minimum pour ne pas réduire
        // la vérification à rien.
        $ok = strlen($saisi) >= 8 && str_ends_with($chiffres($commande->client_telephone), $saisi);

        if ($ok) {
            $liste = array_values(array_unique([
                ...(array) $r->session()->get('commandes_verifiees', []),
                $commande->reference,
            ]));
            $r->session()->put('commandes_verifiees', array_slice($liste, -$this->maxCommandesVerifiees));
        }

        return $ok;
    }

    protected function dejaVerifiee(Request $r, Commande $commande): bool
    {
        return in_array($commande->reference, (array) $r->session()->get('commandes_verifiees', []), true);
    }
}
