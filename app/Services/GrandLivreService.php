<?php

namespace App\Services;

use App\Models\Boutique;
use App\Models\MouvementCompte;
use App\Models\SousCommande;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * =====================================================================
 *  GRAND LIVRE INTERNE
 * =====================================================================
 *  Pourquoi cette classe existe : aucun agrégateur UEMOA ne propose de
 *  sous-compte marchand avec séquestre. La plateforme encaisse donc sur
 *  un compte unique et détient des fonds appartenant aux boutiques.
 *
 *  Il faut donc tenir soi-même le compte de ce qu'on doit à chacun.
 *  C'est exactement ce que fait cette classe, et c'est aussi ce qui
 *  permet de démontrer un cantonnement conforme à l'instruction BCEAO
 *  001-01-2024.
 *
 *  DEUX RÈGLES ABSOLUES, à ne jamais contourner :
 *
 *  1. On n'UPDATE ni ne DELETE jamais un mouvement. Une erreur se
 *     corrige par une écriture inverse. Un grand livre modifiable ne
 *     prouve rien et ne vaut rien.
 *
 *  2. Le solde d'une boutique n'est jamais une colonne. C'est la somme
 *     de ses mouvements, recalculée. Une colonne « solde » finit
 *     toujours par diverger de son historique, et personne ne sait
 *     alors laquelle des deux croire.
 * =====================================================================
 */
class GrandLivreService
{
    /**
     * Enregistre les écritures d'une vente encaissée.
     * Une seule transaction : soit les quatre écritures existent, soit
     * aucune. Un grand livre à moitié écrit est pire qu'un grand livre
     * absent, parce qu'il inspire confiance.
     */
    public function enregistrerVente(SousCommande $sc): void
    {
        DB::transaction(function () use ($sc) {
            $this->ecrire($sc->boutique_id, 'vente', 'credit', $sc->montant_articles_ttc_cfa,
                'sous_commande', $sc->id, "Vente {$sc->reference}");

            if ($sc->commission_cfa > 0) {
                $this->ecrire($sc->boutique_id, 'commission', 'debit', $sc->commission_cfa,
                    'sous_commande', $sc->id,
                    "Commission Afrishop {$sc->taux_commission_pct} %");
                // Contrepartie au compte de la plateforme : ce que la
                // commission rapporte doit apparaître quelque part.
                $this->ecrire(null, 'commission', 'credit', $sc->commission_cfa,
                    'sous_commande', $sc->id, "Commission sur {$sc->reference}");
            }

            if ($sc->retenue_source_cfa > 0) {
                // Débité au vendeur, mais ce n'est pas un revenu pour
                // nous : c'est une dette envers le fisc, suivie dans
                // `retenues_source`.
                $this->ecrire($sc->boutique_id, 'retenue_source', 'debit', $sc->retenue_source_cfa,
                    'sous_commande', $sc->id,
                    "Retenue à la source {$sc->taux_retenue_source_pct} %");
            }
        });
    }

    /** Écriture unitaire. Le seul point d'entrée autorisé du grand livre. */
    public function ecrire(?int $boutiqueId, string $type, string $sens, int $montantCfa,
                           string $pieceType, int $pieceId, string $libelle,
                           ?int $auteurId = null, ?int $annuleMouvementId = null): MouvementCompte
    {
        if ($montantCfa <= 0) {
            throw new RuntimeException("Un mouvement de compte doit porter un montant strictement positif.");
        }

        return MouvementCompte::create([
            'boutique_id'         => $boutiqueId,
            'type'                => $type,
            'sens'                => $sens,
            'montant_cfa'         => $montantCfa,
            'piece_type'          => $pieceType,
            'piece_id'            => $pieceId,
            'libelle'             => $libelle,
            'cree_par_id'         => $auteurId,
            'annule_mouvement_id' => $annuleMouvementId,
        ]);
    }

    /**
     * Contrepasse un mouvement. La seule façon de corriger une erreur.
     * L'écriture d'origine reste visible : c'est le principe même d'un
     * journal probant.
     */
    public function contrepasser(MouvementCompte $mouvement, string $motif, ?int $auteurId = null): MouvementCompte
    {
        if ($mouvement->annule_mouvement_id !== null) {
            throw new RuntimeException("Ce mouvement est déjà une contrepassation.");
        }
        if (MouvementCompte::where('annule_mouvement_id', $mouvement->id)->exists()) {
            throw new RuntimeException("Ce mouvement a déjà été contrepassé.");
        }

        return $this->ecrire(
            $mouvement->boutique_id, 'ajustement',
            $mouvement->sens === 'credit' ? 'debit' : 'credit',
            $mouvement->montant_cfa, $mouvement->piece_type, $mouvement->piece_id,
            "Contrepassation du mouvement #{$mouvement->id} — {$motif}",
            $auteurId, $mouvement->id
        );
    }

    /** Solde d'une boutique : ce que la plateforme lui doit, en francs. */
    public function solde(Boutique $boutique): int
    {
        return (int) MouvementCompte::where('boutique_id', $boutique->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN sens='credit' THEN montant_cfa ELSE -montant_cfa END),0) AS s")
            ->value('s');
    }

    /**
     * Total dû à l'ensemble des boutiques d'un pays.
     * C'est ce montant qui doit se retrouver, au franc près, sur le
     * compte de cantonnement.
     */
    public function totalDuAuxBoutiques(int $paysId): int
    {
        return (int) MouvementCompte::whereNotNull('mouvements_compte.boutique_id')
            ->join('boutiques', 'boutiques.id', '=', 'mouvements_compte.boutique_id')
            ->where('boutiques.pays_id', $paysId)
            ->selectRaw("COALESCE(SUM(CASE WHEN sens='credit' THEN montant_cfa ELSE -montant_cfa END),0) AS s")
            ->value('s');
    }
}
