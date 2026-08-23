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
     * Démarre l'encaissement d'une commande déjà créée (mode_paiement
     * différent de « especes_livraison »).
     *
     * @return array{transaction: TransactionPaiement, statut: string, url_paiement: ?string}
     */
    public function demarrerPaiement(Commande $commande): array
    {
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
     * (driver synchrone) ou par son webhook (PSP réel, asynchrone).
     *
     * IDEMPOTENT : si cette transaction a déjà été finalisée, on ne
     * réécrit rien. Les PSP rejouent leurs webhooks ; sans cette garde,
     * une vente serait créditée deux fois au grand livre.
     */
    public function finaliserReussie(TransactionPaiement $tx): void
    {
        DB::transaction(function () use ($tx) {
            // Verrou + vérification à l'intérieur de la transaction : deux
            // webhooks reçus en parallèle pour la même commande ne
            // doivent jamais passer tous les deux ce point.
            $commande = Commande::with('sousCommandes')->lockForUpdate()->findOrFail($tx->commande_id);

            if ($commande->statut_paiement === 'encaisse') {
                return;
            }

            $tx->update(['statut' => 'reussie', 'finalisee_le' => $tx->finalisee_le ?? now()]);
            $commande->update(['statut_paiement' => 'encaisse']);

            foreach ($commande->sousCommandes as $sc) {
                $this->grandLivre->enregistrerVente($sc);
                $this->retenues->enregistrer($sc);
            }
        });
    }

    /**
     * Le paiement échoue ou est rejeté. Comme PanierService a déjà
     * décrémenté le stock à la création de la commande, il faut le
     * rendre — sinon un panier jamais payé rendrait un article
     * indisponible indéfiniment.
     */
    public function finaliserEchouee(TransactionPaiement $tx, string $motif): void
    {
        DB::transaction(function () use ($tx, $motif) {
            $commande = Commande::with('sousCommandes.lignes.variante')->lockForUpdate()->findOrFail($tx->commande_id);

            if ($commande->statut_paiement === 'echoue') {
                return;
            }

            $tx->update(['statut' => 'echouee', 'finalisee_le' => now(), 'message_erreur' => $motif]);
            $commande->update(['statut_paiement' => 'echoue']);

            foreach ($commande->sousCommandes as $sc) {
                if (! in_array($sc->etat_fonds->value, ['sequestre', 'attente_encaissement'], true)) {
                    continue;
                }

                $this->sequestre->rembourser($sc, $motif);

                foreach ($sc->lignes as $ligne) {
                    $ligne->variante?->increment('stock', $ligne->quantite);
                }
            }
        });
    }

    /** Résout la transaction visée par un webhook, via sa référence externe — la seule clé fiable. */
    public function trouverParReferenceExterne(string $referenceExterne): ?TransactionPaiement
    {
        return TransactionPaiement::where('reference_externe', $referenceExterne)->first();
    }
}
