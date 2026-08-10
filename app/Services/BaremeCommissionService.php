<?php

namespace App\Services;

use App\Models\BaremeCommission;
use Illuminate\Support\Facades\Cache;

/**
 * =====================================================================
 *  BARÈME DE COMMISSION DÉGRESSIF — services
 * =====================================================================
 *  UN TAUX PLAT NE SURVIT PAS AU CHANGEMENT D'ÉCHELLE.
 *
 *  À 10 %, le même taux donne :
 *
 *      formation        75 000 F  →      7 500 F     acceptable
 *      site vitrine    450 000 F  →     45 000 F     discutable
 *      maison       25 000 000 F  →  2 500 000 F     personne ne signe
 *
 *  Le maçon comparerait 2,5 millions à ce que lui coûte un coup de
 *  téléphone, et quitterait la plateforme en emportant le client. La
 *  commission perdue serait totale, pas partielle.
 *
 *  LE CALCUL EST CELUI D'UN IMPÔT SUR LE REVENU : chaque tranche à son
 *  taux, et non le taux de la dernière tranche appliqué au tout. La
 *  nuance n'est pas académique — avec un taux unique choisi par palier,
 *  franchir un seuil ferait BAISSER le revenu net du prestataire, ce qui
 *  l'inciterait à découper son chantier en deux devis. Un barème qui
 *  récompense la fraude est un barème raté.
 *
 *      montant 25 000 000 F
 *        tranche 1 :    500 000 × 10 %  =    50 000
 *        tranche 2 :  4 500 000 ×  5 %  =   225 000
 *        tranche 3 : 20 000 000 ×  1 %  =   200 000
 *                                          ---------
 *                                           475 000 F   soit 1,9 %
 * =====================================================================
 */
class BaremeCommissionService
{
    /**
     * Tranches en vigueur. Mises en cache : elles changent une fois par
     * an au mieux, et cette méthode est appelée à chaque affichage de
     * fiche service.
     */
    public function tranches(string $appliqueA = 'service'): array
    {
        return Cache::remember("bareme.$appliqueA", 3600, function () use ($appliqueA) {
            return BaremeCommission::where('applique_a', $appliqueA)
                ->whereDate('en_vigueur_du', '<=', now())
                ->where(fn ($q) => $q->whereNull('en_vigueur_au')
                                     ->orWhereDate('en_vigueur_au', '>=', now()))
                ->orderBy('ordre')
                ->get(['plafond_cfa', 'taux_pct'])
                ->toArray();
        });
    }

    /**
     * Commission due sur un montant, tranche par tranche.
     *
     * L'arrondi n'intervient qu'À LA FIN, une seule fois. Arrondir
     * chaque tranche accumulerait trois erreurs d'arrondi sur un
     * chantier à trois tranches, et le total ne retomberait pas sur
     * celui qu'un contrôleur recalculerait à la main.
     */
    public function commission(int $montant, string $appliqueA = 'service'): int
    {
        $bas = 0;
        $total = 0.0;

        foreach ($this->tranches($appliqueA) as $t) {
            $haut = $t['plafond_cfa'] !== null ? (int) $t['plafond_cfa'] : PHP_INT_MAX;
            $dansLaTranche = max(0, min($montant, $haut) - $bas);
            $total += $dansLaTranche * (float) $t['taux_pct'] / 100;

            $bas = $haut;
            if ($montant <= $haut) {
                break;
            }
        }

        return (int) round($total);
    }

    /**
     * Taux moyen réellement supporté — le seul chiffre parlant pour un
     * vendeur. « 10 puis 5 puis 1 % » ne lui dit rien ; « 1,9 % sur
     * votre chantier » lui dit tout, et c'est ce qu'il compare à ce que
     * lui coûterait de travailler sans nous.
     */
    public function tauxMoyen(int $montant, string $appliqueA = 'service'): float
    {
        if ($montant <= 0) {
            return 0.0;
        }

        return round($this->commission($montant, $appliqueA) / $montant * 100, 2);
    }

    /**
     * Décomposition lisible, pour la facture et pour l'écran vendeur.
     *
     * Un prestataire qui voit le détail conteste rarement ; un
     * prestataire qui voit un montant net sans explication conteste
     * toujours. Le coût de cette méthode est très inférieur à celui des
     * appels qu'elle évite.
     */
    public function detail(int $montant, string $appliqueA = 'service'): array
    {
        $bas = 0;
        $lignes = [];

        foreach ($this->tranches($appliqueA) as $t) {
            $haut = $t['plafond_cfa'] !== null ? (int) $t['plafond_cfa'] : PHP_INT_MAX;
            $dansLaTranche = max(0, min($montant, $haut) - $bas);

            if ($dansLaTranche > 0) {
                $lignes[] = [
                    'de'       => $bas,
                    'a'        => $haut === PHP_INT_MAX ? null : $haut,
                    'assiette' => $dansLaTranche,
                    'taux'     => (float) $t['taux_pct'],
                    'montant'  => (int) round($dansLaTranche * (float) $t['taux_pct'] / 100),
                ];
            }

            $bas = $haut;
            if ($montant <= $haut) {
                break;
            }
        }

        return [
            'montant'    => $montant,
            'lignes'     => $lignes,
            'commission' => $this->commission($montant, $appliqueA),
            'taux_moyen' => $this->tauxMoyen($montant, $appliqueA),
        ];
    }
}
