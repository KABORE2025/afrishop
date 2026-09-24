<?php

namespace App\Services\Paiement;

use App\Models\Commande;
use App\Models\TransactionPaiement;
use App\Services\GrandLivreService;
use App\Services\RetenueSourceService;
use App\Services\SequestreService;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 *  ORCHESTRATION DU PAIEMENT EN LIGNE D'UNE COMMANDE
 * =====================================================================
 *  PanierService::creerCommande() a déjà éclaté la commande et calculé
 *  ce que chaque boutique doit toucher ; cette classe s'occupe de ce qui
 *  se passe UNE FOIS l'argent réellement encaissé — ou pas.
 *
 *  Deux points d'entrée l'appellent : le contrôleur, quand la passerelle
 *  répond du premier coup (cas de FakeGateway), et le webhook, quand un
 *  vrai PSP confirme de façon asynchrone. Les deux passent par les mêmes
 *  méthodes `finaliser*`, qui sont idempotentes : un webhook rejoué ne
 *  doit jamais écrire deux fois au grand livre.
 * =====================================================================
 */
class PaiementCommandeService
{
    public function __construct(
        private PaymentGatewayInterface $passerelle,
        private GrandLivreService $grandLivre,
        private RetenueSourceService $retenues,
        private SequestreService $sequestre,
    ) {}

    /**
     * Démarre l'encaissement d'une commande déjà créée.
     *
     * @return array{transaction: TransactionPaiement, statut: string, url_paiement: ?string}
     */
    public function demarrerPaiement(Commande $commande): array
    {
        // Une commande annulée (paiement refusé ou expiré) a rendu son
        // stock : la faire payer maintenant encaisserait des articles
        // peut-être déjà revendus. Le client repasse commande.
        if ($commande->fresh()->statut_paiement !== 'attente') {
            throw new \RuntimeException(
                "La commande {$commande->reference} n'attend plus de paiement."
            );
        }

        $tx = TransactionPaiement::create([
            'commande_id'            => $commande->id,
            'operateur_paiement_id'  => null,
            'sens'                   => 'encaissement',
            'montant_cfa'            => $commande->total_a_payer_cfa,
            'statut'                 => 'initiee',
        ]);

        $resultat = $this->passerelle->initier(
            $commande->total_a_payer_cfa,
            $commande->reference,
            ['commande_id' => $commande->id],
        );

        // On enregistre seulement la référence externe ici : le statut et
        // les effets de bord (grand livre, restock) sont du ressort
        // exclusif de finaliserReussie()/finaliserEchouee(), pour que
        // leur garde d'idempotence reste valable quel que soit
        // l'appelant (ici, ou le webhook plus tard).
        $tx->update(['reference_externe' => $resultat['reference_externe']]);

        match ($resultat['statut']) {
            'reussie' => $this->finaliserReussie($tx->fresh()),
            'echouee' => $this->finaliserEchouee($tx->fresh(), "Rejet immédiat par le prestataire."),
            default   => $tx->update(['statut' => 'en_attente']),
        };

        return [
            'transaction'  => $tx->fresh(),
            'statut'       => $resultat['statut'],
            'url_paiement' => $resultat['url_paiement'] ?? null,
        ];
    }

    /**
     * Le paiement est confirmé encaissé — par la passerelle elle-même
     * (driver synchrone), par son webhook, ou par la vérification
     * planifiée (afrishop:verifier-paiements).
     *
     * IDEMPOTENT PAR TRANSACTION : un webhook rejoué ne réécrit rien.
     *
     * C'EST ICI, ET SEULEMENT ICI, QUE L'ARGENT ENTRE EN SÉQUESTRE. Les
     * sous-commandes naissent « attente_encaissement » ; elles passent
     * « sequestre » quand le prestataire confirme — et c'est ce qui les
     * rend expédiables.
     *
     * Deux cas d'argent reçu SANS commande à honorer, qu'on ne perd plus :
     *   · la commande est déjà payée par une autre tentative (le client a
     *     relancé le paiement et les deux ont abouti) ;
     *   · la commande a été annulée — refus, ou expiration du délai de
     *     paiement — et le client a fini par payer quand même.
     * Dans les deux cas, une transaction « remboursement » est créée :
     * l'argent est chez nous, il doit repartir.
     */
    public function finaliserReussie(TransactionPaiement $tx): void
    {
        DB::transaction(function () use ($tx) {
            // Verrou + vérification à l'intérieur de la transaction : deux
            // webhooks reçus en parallèle pour la même commande ne
            // doivent jamais passer tous les deux ce point.
            $commande = Commande::with('sousCommandes')->lockForUpdate()->findOrFail($tx->commande_id);
            $tx = TransactionPaiement::whereKey($tx->id)->lockForUpdate()->firstOrFail();

            if ($tx->statut === 'reussie') {
                return;
            }

            $tx->update(['statut' => 'reussie', 'finalisee_le' => $tx->finalisee_le ?? now()]);

            if ($commande->statut_paiement !== 'attente') {
                $this->rembourserEncaissementOrphelin($commande, $tx);

                return;
            }

            $commande->update(['statut_paiement' => 'encaisse']);

            foreach ($commande->sousCommandes as $sc) {
                if ($sc->etat_fonds->value !== 'attente_encaissement') {
                    continue;
                }

                $sc->update(['etat_fonds' => 'sequestre']);
                $sc->journaliser('paiement_encaisse', [
                    'transaction' => $tx->id, 'reference_externe' => $tx->reference_externe,
                ]);

                $this->grandLivre->enregistrerVente($sc);
                $this->retenues->enregistrer($sc);
            }
        });
    }

    /**
     * Le paiement échoue ou est rejeté.
     *
     * N'annule la commande que si plus AUCUNE autre tentative n'est en
     * cours : un client qui a relancé son paiement ne doit pas voir sa
     * commande annulée par l'échec de la première tentative.
     */
    public function finaliserEchouee(TransactionPaiement $tx, string $motif): void
    {
        DB::transaction(function () use ($tx, $motif) {
            $commande = Commande::with('sousCommandes.lignes.variante')->lockForUpdate()->findOrFail($tx->commande_id);
            $tx = TransactionPaiement::whereKey($tx->id)->lockForUpdate()->firstOrFail();

            // Une transaction déjà dénouée ne se redénoue pas — et un
            // « échec » reçu après un succès ne défait pas un encaissement.
            if (in_array($tx->statut, ['reussie', 'echouee', 'expiree', 'annulee'], true)) {
                return;
            }

            $tx->update([
                'statut' => 'echouee', 'finalisee_le' => now(),
                'message_erreur' => mb_substr($motif, 0, 255),
            ]);

            if ($commande->statut_paiement !== 'attente') {
                return;
            }

            $autreTentative = TransactionPaiement::where('commande_id', $commande->id)
                ->where('sens', 'encaissement')
                ->whereIn('statut', ['initiee', 'en_attente'])
                ->exists();

            if ($autreTentative) {
                return;
            }

            $this->annulerCommande($commande, $motif);
        });
    }

    /**
     * Paiement jamais confirmé dans le délai : la commande est annulée
     * et le stock rendu. Sans cela, un client qui ferme la page de
     * paiement bloquait les articles pour toujours.
     *
     * Appelée par afrishop:verifier-paiements APRÈS avoir redemandé
     * l'état au prestataire : on n'expire que ce qu'il n'a pas pu
     * confirmer. S'il confirme plus tard, finaliserReussie() crée le
     * remboursement — l'argent n'est jamais perdu.
     */
    public function expirer(Commande $commande, string $motif): bool
    {
        return DB::transaction(function () use ($commande, $motif) {
            $commande = Commande::with('sousCommandes.lignes.variante')->lockForUpdate()->findOrFail($commande->id);

            if ($commande->statut_paiement !== 'attente') {
                return false;
            }

            TransactionPaiement::where('commande_id', $commande->id)
                ->where('sens', 'encaissement')
                ->whereIn('statut', ['initiee', 'en_attente'])
                ->update([
                    'statut' => 'expiree', 'finalisee_le' => now(),
                    'message_erreur' => mb_substr($motif, 0, 255),
                ]);

            $this->annulerCommande($commande, $motif);

            return true;
        });
    }

    /**
     * Annule une commande jamais payée et rend le stock. Appelée sous verrou.
     *
     * SEULS LES COLIS ENCORE EN BOUTIQUE sont annulés et remis en stock.
     * Un colis déjà parti (expédié, livré, retourné) n'est plus sur
     * l'étagère : le « rendre » au stock ferait vendre deux fois le même
     * article, et marquer « annulée » une commande livrée effacerait la
     * trace d'une marchandise remise sans paiement. Ce cas n'arrive plus
     * (expedier() exige l'encaissement), mais des commandes antérieures au
     * correctif du 22/09 existent : elles sont signalées pour examen humain.
     */
    private function annulerCommande(Commande $commande, string $motif): void
    {
        $commande->update(['statut_paiement' => 'echoue']);

        foreach ($commande->sousCommandes as $sc) {
            if ($sc->etat_fonds->value !== 'attente_encaissement') {
                continue;
            }

            if (! in_array($sc->statut, ['a_preparer', 'prete'], true)) {
                $sc->journaliser('paiement_absent_colis_parti', ['motif' => $motif, 'statut' => $sc->statut]);
                logger()->warning('Paiement jamais encaissé pour un colis déjà parti : examen humain requis.', [
                    'sous_commande' => $sc->reference, 'statut' => $sc->statut,
                ]);

                continue;
            }

            $this->sequestre->annulerNonEncaissee($sc, $motif);

            foreach ($sc->lignes as $ligne) {
                $ligne->variante?->increment('stock', $ligne->quantite);
            }
        }
    }

    /** Argent reçu pour une commande déjà payée ou annulée : à rendre. */
    private function rembourserEncaissementOrphelin(Commande $commande, TransactionPaiement $tx): void
    {
        $raison = $commande->statut_paiement === 'encaisse'
            ? 'paiement en double (la commande était déjà payée)'
            : 'paiement reçu après annulation de la commande';

        TransactionPaiement::create([
            'commande_id'    => $commande->id,
            'sens'           => 'remboursement',
            'montant_cfa'    => $tx->montant_cfa,
            'statut'         => 'initiee',
            'message_erreur' => mb_substr("À rembourser — {$raison} (transaction {$tx->reference_externe})", 0, 255),
        ]);

        logger()->warning('Encaissement sans commande à honorer : remboursement à effectuer', [
            'commande' => $commande->reference, 'transaction' => $tx->id, 'raison' => $raison,
        ]);
    }

    /** Résout la transaction visée par un webhook, via sa référence externe — la seule clé fiable. */
    public function trouverParReferenceExterne(string $referenceExterne): ?TransactionPaiement
    {
        return TransactionPaiement::where('reference_externe', $referenceExterne)->first();
    }
}
