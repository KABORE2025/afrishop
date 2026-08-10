<?php

namespace App\Services;

use App\Models\Pays;
use App\Models\TauxTaxe;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * =====================================================================
 *  TVA — résolution du taux applicable
 * =====================================================================
 *  Trois variables déterminent le taux : LE PAYS, LA DATE et LA
 *  CATÉGORIE du produit. Aucune ne peut être ignorée.
 *
 *  · Le pays : 18 % dans la plupart des pays de l'UEMOA, mais 19 % au
 *    Niger et en Guinée-Bissau. Coder 18 en dur, c'est se tromper sur
 *    deux marchés dès l'ouverture.
 *  · La date : une loi de finances change un taux au 1er janvier. Une
 *    facture de décembre doit rester recalculable au taux de décembre.
 *  · La catégorie : la Côte d'Ivoire a introduit un taux réduit de 9 %
 *    en janvier 2026 sur certains intrants ; le Burkina applique 10 %
 *    à l'hébergement et à la restauration.
 *
 *  ATTENTION — la plupart des vendeurs d'Afrishop sont HORS CHAMP TVA :
 *  ils relèvent de régimes forfaitaires (CME au Burkina, CGU au
 *  Sénégal, TPU au Togo, Entreprenant en Côte d'Ivoire). Leurs ventes
 *  ne portent aucune TVA. La plateforme, elle, est redevable de la TVA
 *  sur SA COMMISSION. Deux flux fiscaux sur une même transaction : ne
 *  pas les mélanger.
 * =====================================================================
 */
class TaxeService
{
    /**
     * Taux de TVA applicable, en pourcentage.
     *
     * @param  string  $codeTaxe  'tva_normal', 'tva_reduit'… porté par la catégorie
     */
    public function taux(Pays $pays, string $codeTaxe = 'tva_normal', ?CarbonInterface $date = null): float
    {
        $date = $date ?? now();

        $taux = TauxTaxe::where('pays_id', $pays->id)
            ->where('code', $codeTaxe)
            ->where('date_debut', '<=', $date)
            ->where(fn ($q) => $q->whereNull('date_fin')->orWhere('date_fin', '>=', $date))
            ->orderByDesc('date_debut')
            ->first();

        if (! $taux) {
            // On refuse de deviner. Un taux inventé produit des factures
            // fausses, et une facture fausse dans un pays à facturation
            // certifiée est un problème avec l'administration fiscale.
            throw new RuntimeException(
                "Aucun taux « {$codeTaxe} » n'est défini pour {$pays->nom} à la date du "
                . $date->format('d/m/Y') . ". Renseignez-le dans taux_taxe avant d'ouvrir ce pays."
            );
        }

        return (float) $taux->taux_pct;
    }

    /**
     * Ventile un prix TTC en hors taxes et TVA.
     *
     * Les prix sont TOUJOURS saisis et affichés TTC : les lois de
     * protection du consommateur de la zone l'imposent, et c'est de
     * toute façon le seul chiffre qui intéresse l'acheteur.
     *
     * @return array{ht: int, tva: int, ttc: int, taux: float}
     */
    public function ventiler(int $prixTtcCfa, float $tauxPct): array
    {
        if ($tauxPct <= 0) {
            return ['ht' => $prixTtcCfa, 'tva' => 0, 'ttc' => $prixTtcCfa, 'taux' => 0.0];
        }

        // Arrondi à l'entier inférieur pour le HT, le reste va à la TVA :
        // ainsi HT + TVA = TTC exactement, toujours. L'inverse
        // (arrondir la TVA) laisse dériver le total d'un franc.
        $ht = (int) floor($prixTtcCfa / (1 + $tauxPct / 100));

        return ['ht' => $ht, 'tva' => $prixTtcCfa - $ht, 'ttc' => $prixTtcCfa, 'taux' => $tauxPct];
    }

    /**
     * Une vente donnée porte-t-elle de la TVA ?
     *
     * Non si la boutique n'est pas assujettie — ce qui est le cas de la
     * grande majorité des artisans. Facturer de la TVA pour eux serait
     * une faute : ils n'ont pas le droit de la collecter.
     */
    public function venteAssujettie(\App\Models\Boutique $boutique): bool
    {
        return $boutique->assujetti_tva && $boutique->numero_fiscal !== null;
    }
}
