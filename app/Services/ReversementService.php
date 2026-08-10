<?php

namespace App\Services;

use App\Models\Boutique;
use App\Models\Reversement;
use App\Models\SousCommande;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 *  REVERSEMENTS AUX BOUTIQUES
 * =====================================================================
 *  Regroupe les ventes libérées en UN SEUL virement par boutique et par
 *  période. Les frais de transfert Mobile Money sont fixes par
 *  opération : payer douze ventes en douze virements coûte douze fois
 *  ces frais pour le même montant net.
 *
 *  Nouveautés v2 : un reversement peut être SUSPENDU — plafond de
 *  portefeuille atteint, connaissance client incomplète, contrôle en
 *  cours. Verser vers un portefeuille saturé produit un échec silencieux
 *  chez l'opérateur, et le vendeur croit qu'on ne l'a pas payé.
 * =====================================================================
 */
class ReversementService
{
    public function __construct(private GrandLivreService $grandLivre) {}

    /** @return array<int, Reversement> */
    public function preparerPeriode(\DateTimeInterface $debut, \DateTimeInterface $fin): array
    {
        $minimum = (int) parametre('reversement_minimum_cfa', 5000);
        $plafond = (int) parametre('plafond_wallet_identifie_cfa', 2_000_000);
        $prepares = [];

        Boutique::where('statut', 'actif')->with('utilisateur')
            ->chunkById(100, function ($boutiques) use ($debut, $fin, $minimum, $plafond, &$prepares) {
                foreach ($boutiques as $boutique) {

                    // Ventes éligibles : fonds libérés, sur la période,
                    // et PAS DÉJÀ rattachées à un reversement. Le
                    // whereNotExists est la première protection contre
                    // le double paiement ; l'index unique en base est le
                    // filet de sécurité si celle-ci échoue.
                    $ventes = SousCommande::where('boutique_id', $boutique->id)
                        ->where('etat_fonds', 'reverse')
                        ->whereBetween('livre_le', [$debut, $fin])
                        ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('reversement_lignes')
                            ->whereColumn('reversement_lignes.sous_commande_id', 'sous_commandes.id'))
                        ->get();

                    if ($ventes->isEmpty()) {
                        continue;
                    }

                    $net = (int) $ventes->sum('montant_net_cfa');

                    // En dessous du seuil, on attend : les frais fixes
                    // rendraient l'opération absurde.
                    if ($net < $minimum) {
                        continue;
                    }

                    $suspension = $this->motifDeSuspension($boutique, $net, $plafond);

                    $prepares[] = DB::transaction(function () use ($boutique, $ventes, $debut, $fin, $net, $suspension) {
                        $rev = Reversement::create([
                            'boutique_id'        => $boutique->id,
                            'reference'          => $this->prochaineReference($boutique),
                            'periode_debut'      => $debut,
                            'periode_fin'        => $fin,
                            'montant_brut_cfa'   => (int) $ventes->sum('montant_articles_ttc_cfa'),
                            'commission_cfa'     => (int) $ventes->sum('commission_cfa'),
                            'retenue_source_cfa' => (int) $ventes->sum('retenue_source_cfa'),
                            'montant_net_cfa'    => $net,
                            'statut'             => $suspension ? 'suspendu' : 'a_payer',
                            'motif_suspension'   => $suspension,
                        ]);

                        DB::table('reversement_lignes')->insert(
                            $ventes->map(fn ($v) => [
                                'reversement_id'   => $rev->id,
                                'sous_commande_id' => $v->id,
                            ])->all()
                        );

                        // Le grand livre enregistre la sortie : sans
                        // cette écriture, le solde de la boutique
                        // resterait créditeur après avoir été payée.
                        $this->grandLivre->ecrire($boutique->id, 'reversement', 'debit', $net,
                            'reversement', $rev->id, "Versement {$rev->reference}");

                        return $rev;
                    });
                }
            });

        return $prepares;
    }

    /**
     * Un versement doit-il être retenu ?
     * Mieux vaut suspendre et expliquer que tenter et échouer :
     * l'échec côté opérateur est silencieux, et le vendeur en conclut
     * qu'on ne l'a pas payé.
     */
    protected function motifDeSuspension(Boutique $boutique, int $net, int $plafond): ?string
    {
        if ($boutique->paiement_numero === null || $boutique->paiement_verifie_le === null) {
            return "Le compte de reversement n'est pas vérifié.";
        }
        if ($boutique->utilisateur?->kyc_niveau === 'aucun') {
            return "Identification incomplète : le plafond réglementaire est de 200 000 FCFA par mois.";
        }
        if ($net > $plafond) {
            return "Le montant dépasse le plafond de portefeuille de "
                 . number_format($plafond, 0, ',', ' ') . " FCFA. À fractionner ou à virer par banque.";
        }

        return null;
    }

    protected function prochaineReference(Boutique $boutique): string
    {
        $annee = now()->year;
        $prefixe = $boutique->pays->code_iso2 . "-REV-{$annee}-";
        $dernier = Reversement::where('reference', 'like', $prefixe . '%')->max('reference');
        $n = $dernier ? ((int) substr($dernier, -5)) + 1 : 1;

        return sprintf('%s%05d', $prefixe, $n);
    }
}
