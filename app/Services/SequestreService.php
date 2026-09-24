<?php

namespace App\Services;

use App\Models\MouvementCompte;
use App\Models\RetenueSource;
use App\Models\SousCommande;
use App\Models\TransactionPaiement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * =====================================================================
 *  SÉQUESTRE DES FONDS (version 2)
 * =====================================================================
 *  Ce qui change par rapport à la première conception :
 *
 *  1. Le séquestre n'est plus « chez le prestataire, sur le sous-compte
 *     de la boutique » — ce montage n'existe pas en UEMOA. Les fonds
 *     sont sur le compte de cantonnement de la plateforme, et le grand
 *     livre dit à qui ils appartiennent.
 *
 *  2. Un état d'entrée : « attente_encaissement ». Le client paie en
 *     Mobile Money AVANT l'expédition ; tant que le prestataire n'a pas
 *     confirmé l'encaissement, il n'y a rien à séquestrer — et rien ne
 *     doit partir (VendeurController::expedier() le vérifie).
 *
 *  3. Un remboursement CONTREPASSE le grand livre. Sans cela, une
 *     vente remboursée restait créditée à la boutique : chiffre
 *     d'affaires faux, commission encaissée sur une vente annulée,
 *     retenue déclarée au fisc sur une base qui n'existe plus.
 *
 *  Trois conditions cumulatives pour libérer les fonds :
 *    le colis est livré · aucun litige n'est ouvert · le client a
 *    confirmé, ou le délai de confirmation automatique est écoulé.
 *
 *  La troisième condition est celle qui évite qu'un vendeur attende
 *  indéfiniment parce qu'un client a simplement oublié de cliquer.
 * =====================================================================
 */
class SequestreService
{
    public function liberer(SousCommande $sc, string $origine = 'systeme'): void
    {
        DB::transaction(function () use ($sc, $origine) {
            // VERROU, puis vérification SUR LA LIGNE VERROUILLÉE. Vérifier
            // avant de verrouiller laissait une fenêtre : un litige ouvert
            // (LitigeService::ouvrir() verrouille la même ligne) ou un
            // remboursement passé entre-temps ne se voyait pas, et la
            // boutique était payée sur des fonds déjà contestés ou rendus.
            $sc = SousCommande::whereKey($sc->id)->lockForUpdate()->firstOrFail();

            // Un arbitrage en faveur de la boutique TRANCHE : attendre en plus
            // la fin de la fenêtre de protection n'aurait plus d'objet — le
            // client a déjà exercé son droit de contester, et il a été entendu.
            if (! $this->liberables($sc, ignorerDelai: $origine === 'arbitrage')) {
                throw new RuntimeException(
                    "Les fonds de {$sc->reference} ne sont pas libérables : " . $this->raisonBlocage($sc)
                );
            }

            $sc->update(['etat_fonds' => 'reverse']);
            $sc->journaliser('fonds_liberes',
                ['montant_net' => $sc->montant_net_cfa, 'origine' => $origine],
                auteurType: $origine === 'client' ? 'client' : 'systeme');
        });
    }

    public function liberables(SousCommande $sc, bool $ignorerDelai = false): bool
    {
        if ($sc->statut !== 'livree')             return false;
        if ($sc->etat_fonds->value !== 'sequestre') return false;
        if ($sc->aUnLitigeOuvert())            return false;
        // Un retour en cours gèle aussi les fonds : rembourser après
        // avoir payé le vendeur oblige à lui reprendre l'argent, ce qui
        // est toujours une mauvaise conversation.
        if ($sc->aUnRetourEnCours())           return false;

        if ($ignorerDelai || $sc->confirme_par_client_le !== null) return true;

        $delai = (int) parametre('delai_confirmation_auto_jours', 3);

        return $sc->livre_le !== null && $sc->livre_le->addDays($delai)->isPast();
    }

    public function __construct(private GrandLivreService $grandLivre) {}

    /**
     * Rembourse le client d'une sous-commande dont l'argent est en
     * séquestre.
     *
     * Trois effets, dans une seule transaction :
     *   1. contrepassation de toutes les écritures de la vente
     *      (vente, commission boutique ET plateforme, retenue) ;
     *   2. annulation de la retenue à la source non encore déclarée ;
     *   3. création d'une transaction « remboursement » à exécuter —
     *      c'est la file de travail de l'opérateur tant qu'aucun
     *      remboursement automatique n'est branché chez le prestataire.
     *
     * Sur une sous-commande jamais encaissée, il n'y a rien à rendre :
     * on l'annule simplement (voir annulerNonEncaissee()).
     */
    public function rembourser(SousCommande $sc, string $motif, ?int $auteurId = null,
                               string $statutFinal = 'annulee', string $auteurType = 'admin'): void
    {
        if ($sc->etat_fonds->value === 'attente_encaissement') {
            $this->annulerNonEncaissee($sc, $motif, $auteurId, $auteurType);

            return;
        }

        if ($sc->etat_fonds->value !== 'sequestre') {
            throw new RuntimeException(
                "Impossible de rembourser {$sc->reference} : les fonds sont déjà « {$sc->etat_fonds->value} »."
            );
        }

        DB::transaction(function () use ($sc, $motif, $auteurId, $statutFinal, $auteurType) {
            $sc = SousCommande::whereKey($sc->id)->lockForUpdate()->firstOrFail();

            if ($sc->etat_fonds->value !== 'sequestre') {
                throw new RuntimeException("Les fonds de {$sc->reference} ont changé d'état entre-temps.");
            }

            $contrepasses = $this->contrepasserVente($sc, $motif, $auteurId);

            $retenuesAnnulees = RetenueSource::where('sous_commande_id', $sc->id)
                ->where('statut', 'a_reverser')
                ->update(['statut' => 'annulee']);

            // Une retenue déjà DÉCLARÉE ne s'annule pas d'un clic : elle
            // se régularise sur la déclaration suivante. On la signale.
            $retenuesDeclarees = RetenueSource::where('sous_commande_id', $sc->id)
                ->whereIn('statut', ['declaree', 'reversee'])->count();

            $montant = (int) $sc->montant_articles_ttc_cfa + (int) $sc->frais_livraison_cfa;

            $remboursement = TransactionPaiement::create([
                'commande_id'    => $sc->commande_id,
                'sens'           => 'remboursement',
                'montant_cfa'    => $montant,
                'statut'         => 'initiee',
                'message_erreur' => mb_substr("À rembourser — {$sc->reference} : {$motif}", 0, 255),
            ]);

            $sc->update(['etat_fonds' => 'rembourse', 'statut' => $statutFinal]);
            $sc->journaliser('rembourse', [
                'montant'                 => $montant,
                'motif'                   => $motif,
                'transaction_remboursement' => $remboursement->id,
                'ecritures_contrepassees' => $contrepasses,
                'retenues_annulees'       => $retenuesAnnulees,
                'retenues_a_regulariser'  => $retenuesDeclarees,
            ], $auteurId, $auteurType);

            $commande = $sc->commande;
            $commande->rafraichirStatut();

            $restantes = $commande->sousCommandes()->where('etat_fonds', '!=', 'rembourse')->count();
            if ($restantes === 0) {
                $commande->update(['statut_paiement' => 'rembourse']);
            }
        });
    }

    /**
     * Annule une sous-commande dont le paiement n'a jamais abouti :
     * refusé, abandonné, expiré. Aucune écriture au grand livre n'existe
     * (elles naissent à l'encaissement), donc aucune à contrepasser, et
     * rien à rendre au client. Le stock est rendu par l'appelant.
     */
    public function annulerNonEncaissee(SousCommande $sc, string $motif, ?int $auteurId = null,
                                        string $auteurType = 'systeme'): void
    {
        if ($sc->etat_fonds->value !== 'attente_encaissement') {
            throw new RuntimeException(
                "{$sc->reference} n'est pas en attente d'encaissement (« {$sc->etat_fonds->value} »)."
            );
        }

        DB::transaction(function () use ($sc, $motif, $auteurId, $auteurType) {
            $sc->update(['etat_fonds' => 'impaye', 'statut' => 'annulee']);
            $sc->journaliser('annulee_non_payee', ['motif' => $motif], $auteurId, $auteurType);
            $sc->commande->rafraichirStatut();
        });
    }

    /**
     * Clôture d'un retour : le colis que le livreur n'a pas pu remettre
     * est revenu à la boutique. C'est SEULEMENT maintenant que le client
     * est remboursé et que le stock est rendu — pas au moment où le
     * livreur déclare l'échec. Avant, trois clics d'un livreur
     * suffisaient à rembourser une commande et à remettre en vente un
     * article qui n'était pas revenu.
     */
    public function cloturerRetourColis(SousCommande $sc, string $motif, ?int $auteurId, string $auteurType): void
    {
        DB::transaction(function () use ($sc, $motif, $auteurId, $auteurType) {
            $sc = SousCommande::with(['expedition', 'lignes.variante', 'commande'])
                ->whereKey($sc->id)->lockForUpdate()->firstOrFail();

            if ($sc->statut !== 'expediee' || $sc->expedition?->statut !== 'retour_expediteur') {
                throw new RuntimeException("{$sc->reference} n'a pas de retour de colis en cours.");
            }

            $this->rembourser($sc, $motif, $auteurId, 'retournee', $auteurType);

            foreach ($sc->lignes as $ligne) {
                $ligne->variante?->increment('stock', $ligne->quantite);
            }

            $sc->journaliser('retour_recu', ['motif' => $motif], $auteurId, $auteurType);
        });
    }

    /**
     * Contrepasse, une à une, les écritures d'une vente encore actives.
     * Rejouable : une écriture déjà contrepassée est ignorée, jamais
     * contrepassée deux fois (GrandLivreService le refuserait).
     */
    private function contrepasserVente(SousCommande $sc, string $motif, ?int $auteurId): int
    {
        $n = 0;

        MouvementCompte::where('piece_type', 'sous_commande')
            ->where('piece_id', $sc->id)
            ->whereNull('annule_mouvement_id')
            ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('mouvements_compte as c')
                ->whereColumn('c.annule_mouvement_id', 'mouvements_compte.id'))
            ->orderBy('id')
            ->get()
            ->each(function (MouvementCompte $m) use ($motif, $auteurId, &$n) {
                $this->grandLivre->contrepasser($m, "Remboursement : {$motif}", $auteurId);
                $n++;
            });

        return $n;
    }

    /**
     * Balaie les livraisons libérables : délai écoulé, OU confirmées par
     * le client mais restées bloquées (retour ou litige clos depuis).
     *
     * Les confirmées ne sont PAS exclues : une confirmation posée pendant
     * un blocage ne libère rien sur le moment, et sans ce balayage plus
     * rien ne paierait jamais la boutique une fois le blocage levé.
     */
    public function libererLesEchues(): int
    {
        $delai = (int) parametre('delai_confirmation_auto_jours', 3);
        $n = 0;

        SousCommande::where('etat_fonds', 'sequestre')
            ->where('statut', 'livree')
            ->where(fn ($q) => $q->whereNotNull('confirme_par_client_le')
                ->orWhere('livre_le', '<=', now()->subDays($delai)))
            ->chunkById(200, function ($lot) use (&$n) {
                foreach ($lot as $sc) {
                    if (! $this->liberables($sc)) {
                        continue;
                    }
                    try {
                        $this->liberer($sc, $sc->confirme_par_client_le ? 'client' : 'delai_ecoule');
                        $n++;
                    } catch (RuntimeException) {
                        // État changé entre la lecture et le verrou (litige
                        // ouvert à l'instant) : on laisse, sans arrêter le
                        // balayage des autres.
                    }
                }
            });

        return $n;
    }

    /** Explique en français pourquoi les fonds sont bloqués. */
    public function raisonBlocage(SousCommande $sc): string
    {
        return match (true) {
            $sc->etat_fonds->value === 'attente_encaissement' =>
                'le paiement du client n\'est pas encore confirmé par le prestataire',
            $sc->etat_fonds->value === 'impaye' =>
                'la commande a été annulée sans paiement : rien n\'a été encaissé',
            $sc->etat_fonds->value !== 'sequestre' =>
                "les fonds sont déjà « {$sc->etat_fonds->value} »",
            $sc->statut !== 'livree' =>
                "le colis n'est pas encore livré ({$sc->statut})",
            $sc->aUnLitigeOuvert() => 'un litige est en cours d\'examen',
            $sc->aUnRetourEnCours() => 'un retour est en cours de traitement',
            default =>
                'fenêtre de protection du client en cours ('
                . ((int) parametre('delai_confirmation_auto_jours', 3) * 24) . ' h après la remise)',
        };
    }
}
