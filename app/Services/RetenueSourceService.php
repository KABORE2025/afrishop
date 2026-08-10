<?php

namespace App\Services;

use App\Models\Boutique;
use App\Models\RetenueSource;
use App\Models\SousCommande;

/**
 * =====================================================================
 *  RETENUE À LA SOURCE
 * =====================================================================
 *  Sujet découvert tardivement, et qui change l'économie du produit
 *  pour les vendeurs.
 *
 *  Plusieurs pays imposent au payeur de retenir une part des sommes
 *  versées à un prestataire, avec un taux MAJORÉ quand celui-ci n'est
 *  pas immatriculé :
 *
 *    Burkina Faso  : 25 % sans numéro fiscal (5 % avec), exonéré < 50 000 F
 *    Bénin         : 5 % sans immatriculation (AIB)
 *    Côte d'Ivoire : 5 % sur les opérations avec le secteur informel (AIRSI)
 *
 *  Concrètement, un artisan burkinabè sans IFU qui vend 24 000 F ne
 *  touche que 15 120 F après commission et retenue, soit 63 % — contre
 *  85 % s'il était immatriculé. Aucune négociation de commission ne
 *  rattrape un tel écart.
 *
 *  CONSÉQUENCE PRODUIT : afficher cette différence au vendeur, dans son
 *  espace, en francs et pas en pourcentage. C'est le meilleur argument
 *  d'accompagnement vers l'immatriculation — et une immatriculation de
 *  plus, c'est un vendeur qui reste.
 *
 *  L'argent retenu N'EST PAS UN REVENU de la plateforme : il est
 *  collecté pour le compte du fisc et doit lui être reversé.
 * =====================================================================
 */
class RetenueSourceService
{
    /** Taux applicable à une boutique donnée, en pourcentage. */
    public function taux(Boutique $boutique, int $baseCfa): float
    {
        $pays = $boutique->pays;

        // Certains pays exonèrent les petits montants. Retenir 25 % sur
        // une vente de 2 000 F coûterait plus cher à déclarer qu'elle
        // ne rapporte.
        if ($pays->retenue_source_seuil_cfa !== null && $baseCfa < $pays->retenue_source_seuil_cfa) {
            return 0.0;
        }

        $immatricule = $boutique->numero_fiscal !== null
                    && $boutique->regime_fiscal !== 'non_immatricule';

        $taux = $immatricule
            ? $pays->retenue_source_immatricule_pct
            : $pays->retenue_source_non_immatricule_pct;

        // NULL signifie « non vérifié pour ce pays », pas « zéro ».
        // On ne retient rien plutôt que de retenir au hasard, et le
        // registre des manques signale les pays concernés.
        return $taux === null ? 0.0 : (float) $taux;
    }

    public function calculer(Boutique $boutique, int $baseCfa): int
    {
        $taux = $this->taux($boutique, $baseCfa);

        return $taux <= 0 ? 0 : (int) round($baseCfa * $taux / 100);
    }

    /** Enregistre la retenue pour la déclaration fiscale du mois. */
    public function enregistrer(SousCommande $sousCommande): ?RetenueSource
    {
        if ($sousCommande->retenue_source_cfa <= 0) {
            return null;
        }

        $boutique = $sousCommande->boutique;
        $immatricule = $boutique->numero_fiscal !== null
                    && $boutique->regime_fiscal !== 'non_immatricule';

        return RetenueSource::create([
            'pays_id'          => $boutique->pays_id,
            'boutique_id'      => $boutique->id,
            'sous_commande_id' => $sousCommande->id,
            'base_cfa'         => $sousCommande->montant_articles_ttc_cfa,
            'taux_pct'         => $sousCommande->taux_retenue_source_pct,
            'montant_cfa'      => $sousCommande->retenue_source_cfa,
            'motif'            => $immatricule ? 'immatricule' : 'non_immatricule',
            'periode'          => now()->format('Y-m'),
            'statut'           => 'a_reverser',
        ]);
    }

    /**
     * Ce que la boutique gagnerait si elle s'immatriculait, sur une
     * période donnée. Destiné à être affiché dans l'espace vendeur.
     */
    public function gainPotentielImmatriculation(Boutique $boutique, string $periode): int
    {
        if ($boutique->numero_fiscal !== null) {
            return 0;
        }

        $retenu = RetenueSource::where('boutique_id', $boutique->id)
            ->where('periode', $periode)->sum('montant_cfa');

        $tauxReduit = (float) ($boutique->pays->retenue_source_immatricule_pct ?? 0);
        $tauxActuel = (float) ($boutique->pays->retenue_source_non_immatricule_pct ?? 0);

        if ($tauxActuel <= 0) {
            return 0;
        }

        return (int) round($retenu * (1 - $tauxReduit / $tauxActuel));
    }
}
