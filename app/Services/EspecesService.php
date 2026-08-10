<?php

namespace App\Services;

use App\Models\EncaissementEspeces;
use App\Models\Expedition;
use App\Models\SousCommande;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * =====================================================================
 *  PAIEMENT À LA LIVRAISON
 * =====================================================================
 *  Le mode dominant du marché, et de loin le plus risqué à exploiter.
 *  Il n'était pas modélisé dans la première conception : c'est le
 *  manque le plus coûteux du registre.
 *
 *  Ce qui le distingue d'un paiement en ligne :
 *   · rien n'est encaissé à la commande — il n'y a donc rien à
 *     séquestrer, et l'état des fonds naît en « attente_encaissement » ;
 *   · le client peut refuser le colis à sa porte. Le vendeur a préparé,
 *     le livreur s'est déplacé, et personne n'est payé ;
 *   · le livreur détient physiquement de l'argent qui appartient aux
 *     boutiques. Sans suivi ligne à ligne, la démarque est invisible.
 * =====================================================================
 */
class EspecesService
{
    public function __construct(private GrandLivreService $grandLivre) {}

    /** Prépare l'encaissement au moment où le colis part chez le livreur. */
    public function preparer(Expedition $expedition): EncaissementEspeces
    {
        $sc = $expedition->sousCommande;

        return EncaissementEspeces::create([
            'expedition_id'   => $expedition->id,
            'livreur_id'      => $expedition->livreur_id,
            'transporteur_id' => $expedition->transporteur_id,
            'montant_du_cfa'  => $sc->montant_articles_ttc_cfa + $sc->frais_livraison_cfa,
            'statut'          => 'a_encaisser',
        ]);
    }

    /**
     * Le livreur a encaissé et le code de livraison a été validé.
     * C'est SEULEMENT à ce moment que les écritures comptables
     * apparaissent : avant, il n'y avait qu'une promesse.
     */
    public function encaisser(EncaissementEspeces $enc, int $montantPercuCfa): void
    {
        if ($enc->statut !== 'a_encaisser') {
            throw new RuntimeException("Cet encaissement a déjà été traité ({$enc->statut}).");
        }

        DB::transaction(function () use ($enc, $montantPercuCfa) {
            $sc = $enc->expedition->sousCommande;

            $enc->update([
                'montant_percu_cfa' => $montantPercuCfa,
                'statut'            => 'encaisse',
            ]);

            $sc->update(['etat_fonds' => 'sequestre']);
            $this->grandLivre->enregistrerVente($sc);

            // Un écart entre le dû et le perçu n'est jamais anodin :
            // c'est une remise sauvage, une erreur de rendu de monnaie
            // ou un détournement. On le trace explicitement.
            if ($montantPercuCfa !== $enc->montant_du_cfa) {
                $sc->journaliser('ecart_encaissement', [
                    'du'    => $enc->montant_du_cfa,
                    'percu' => $montantPercuCfa,
                    'ecart' => $montantPercuCfa - $enc->montant_du_cfa,
                ], auteurType: 'livreur');
            }
        });
    }

    /**
     * Le client refuse le colis à sa porte.
     * Personne n'est payé, mais tout le monde a travaillé. On enregistre
     * l'impayé pour pouvoir mesurer le phénomène par client ET par
     * vendeur — c'est la seule façon de décider, plus tard, qui doit
     * passer au prépaiement obligatoire.
     */
    public function refuser(EncaissementEspeces $enc, string $motif): void
    {
        DB::transaction(function () use ($enc, $motif) {
            $enc->update(['statut' => 'refuse_client', 'ecart_commentaire' => $motif]);
            $enc->expedition->update(['statut' => 'echouee', 'motif_echec' => $motif]);

            $sc = $enc->expedition->sousCommande;
            $sc->update(['statut' => 'annulee', 'etat_fonds' => 'impaye']);
            $sc->journaliser('refus_a_la_porte', ['motif' => $motif], auteurType: 'livreur');

            // Le stock est rendu : la marchandise revient chez le vendeur.
            foreach ($sc->lignes as $ligne) {
                $ligne->variante?->increment('stock', $ligne->quantite);
            }
        });
    }

    /** Remise des fonds par le livreur, contre bordereau. */
    public function remettre(EncaissementEspeces $enc, string $bordereauRef): void
    {
        $enc->update(['statut' => 'remis', 'remis_le' => now(), 'bordereau_ref' => $bordereauRef]);
    }

    /**
     * Espèces encaissées mais pas encore remises, par livreur.
     * À surveiller quotidiennement : c'est de l'argent qui n'est ni
     * chez le vendeur, ni chez nous, ni à la banque.
     */
    public function enAttenteDeRemise(): \Illuminate\Support\Collection
    {
        return EncaissementEspeces::where('statut', 'encaisse')
            ->selectRaw('livreur_id, COUNT(*) AS nb, SUM(montant_percu_cfa) AS total_cfa')
            ->groupBy('livreur_id')->get();
    }
}
