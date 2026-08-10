<?php

namespace App\Services;

use App\Models\Boutique;
use App\Models\Commande;
use App\Models\Pays;
use App\Models\VarianteProduit;
use App\Models\ZoneLivraison;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * =====================================================================
 *  PANIER → COMMANDE (version multi-pays)
 * =====================================================================
 *  Transforme un panier en commande éclatée par boutique, en calculant
 *  au passage la TVA, la commission et la retenue à la source — trois
 *  prélèvements qui s'appliquent en cascade sur les mêmes sommes et
 *  qu'il ne faut pas intervertir.
 *
 *  Nouveautés de cette version :
 *   · les VARIANTES portent le stock et le prix ;
 *   · les PLAFONDS de monnaie électronique sont contrôlés avant de
 *     lancer un paiement qui échouerait ;
 *   · le PAIEMENT À LA LIVRAISON n'encaisse rien : la sous-commande
 *     naît en « attente_encaissement », pas en « séquestre ».
 * =====================================================================
 */
class PanierService
{
    public function __construct(
        private TaxeService $taxes,
        private RetenueSourceService $retenues,
    ) {}

    /**
     * Frais de livraison.
     * Tarif de base pour la première boutique, supplément pour chaque
     * boutique additionnelle : trois boutiques ne coûtent pas trois
     * livraisons — sinon personne n'achèterait multi-boutiques, ce qui
     * viderait la place de marché de son intérêt.
     */
    public function calculerFrais(Pays $pays, ?int $villeId, ?string $quartier, int $nbBoutiques, int $poidsTotalG = 0): int
    {
        if ($nbBoutiques < 1) {
            return 0;
        }

        $zone = ZoneLivraison::where('pays_id', $pays->id)
            ->where('active', true)
            ->where(fn ($q) => $q->where('ville_id', $villeId)->orWhereNull('ville_id'))
            ->where(fn ($q) => $q->where('quartier', $quartier)->orWhereNull('quartier'))
            ->orderByRaw('quartier IS NULL')   // le tarif du quartier prime sur celui de la ville
            ->orderByRaw('ville_id IS NULL')
            ->first();

        if (! $zone) {
            throw new RuntimeException("Aucune livraison n'est assurée à cette adresse pour le moment.");
        }

        $frais = $zone->frais_base_cfa + ($nbBoutiques - 1) * $zone->frais_boutique_sup_cfa;

        if ($zone->frais_par_kg_cfa > 0 && $poidsTotalG > 0) {
            $frais += (int) ceil($poidsTotalG / 1000) * $zone->frais_par_kg_cfa;
        }

        return $frais;
    }

    /**
     * Vérifie qu'un montant peut réellement être payé par le moyen choisi.
     *
     * Les plafonds de monnaie électronique de la BCEAO sont durs :
     * 2 000 000 F de solde maximum sur un portefeuille identifié,
     * 200 000 F par mois s'il ne l'est pas. Lancer un paiement voué à
     * l'échec est la pire expérience possible — le client a validé sa
     * commande, il croit avoir payé, et rien n'arrive.
     */
    public function verifierPlafond(int $montantCfa, string $modePaiement, ?int $operateurId = null): void
    {
        if ($modePaiement !== 'mobile_money') {
            return;
        }

        $plafond = (int) parametre('panier_max_mobile_money_cfa', 1_500_000);

        if ($montantCfa > $plafond) {
            throw new RuntimeException(
                "Le montant de " . number_format($montantCfa, 0, ',', ' ') . " FCFA dépasse le plafond "
                . "des paiements Mobile Money. Choisissez le paiement à la livraison ou un virement, "
                . "ou passez deux commandes séparées."
            );
        }
    }

    /**
     * Crée la commande et ses sous-commandes.
     *
     * @param  array<int, array{variante_id:int, quantite:int}>  $articles
     */
    public function creerCommande(array $articles, array $client, Pays $pays, ?int $utilisateurId = null): Commande
    {
        if ($articles === []) {
            throw new RuntimeException('Le panier est vide.');
        }

        return DB::transaction(function () use ($articles, $client, $pays, $utilisateurId) {

            // Verrou pessimiste : sans lui, deux clients achetant le
            // dernier article au même instant passeraient tous les deux.
            $variantes = VarianteProduit::whereIn('id', array_column($articles, 'variante_id'))
                ->with('produit.boutique.pays', 'produit.categorie')
                ->lockForUpdate()->get()->keyBy('id');

            $parBoutique = [];
            $poidsTotal = 0;

            foreach ($articles as $a) {
                $v = $variantes->get($a['variante_id']) ?? throw new RuntimeException("Article introuvable.");
                $p = $v->produit;
                $b = $p->boutique;

                if (! $v->actif || ! $p->actif || $p->statut_moderation !== 'publie') {
                    throw new RuntimeException("« {$p->nom} » n'est plus en vente.");
                }
                if ($v->stock < $a['quantite']) {
                    throw new RuntimeException("Stock insuffisant pour « {$p->nom} » : il en reste {$v->stock}.");
                }
                if (! $b->peutVendre()) {
                    throw new RuntimeException("La boutique « {$b->nom} » ne peut pas recevoir de commande actuellement.");
                }

                $parBoutique[$b->id][] = ['variante' => $v, 'quantite' => $a['quantite']];
                $poidsTotal += ($v->poids_g ?? $p->poids_g ?? 0) * $a['quantite'];
            }

            $frais = $this->calculerFrais($pays, $client['ville_id'] ?? null,
                $client['quartier'] ?? null, count($parBoutique), $poidsTotal);

            $totalArticles = array_sum(array_map(
                fn ($lignes) => array_sum(array_map(
                    fn ($l) => $l['variante']->prixTtc() * $l['quantite'], $lignes)),
                $parBoutique));

            $this->verifierPlafond($totalArticles + $frais, $client['mode_paiement']);

            $commande = Commande::create([
                'reference'                 => Commande::prochaineReference($pays),
                'pays_id'                   => $pays->id,
                'utilisateur_id'            => $utilisateurId,
                'client_nom'                => $client['nom'],
                'client_telephone'          => $client['telephone'],
                'ville_id'                  => $client['ville_id'] ?? null,
                'quartier'                  => $client['quartier'],
                'repere'                    => $client['repere'] ?? null,
                'mode_livraison'            => $client['mode_livraison'] ?? 'domicile',
                'mode_paiement'             => $client['mode_paiement'],
                'statut'                    => 'confirmee',
                'statut_paiement'           => 'attente',
                'total_frais_livraison_cfa' => $frais,
                'cgv_version'               => $client['cgv_version'] ?? null,
                'cgv_acceptees_le'          => now(),
                'confirmee_le'              => now(),
            ]);

            // Le paiement à la livraison n'encaisse rien : il n'y a donc
            // rien à séquestrer. Confondre les deux états rendrait le
            // grand livre faux dès la première commande en espèces.
            $etatFonds = $client['mode_paiement'] === 'especes_livraison'
                ? 'attente_encaissement' : 'sequestre';

            // Répartition des frais : le reste de la division entière
            // va à la première boutique. Sinon 1000 F sur 3 boutiques
            // donnent 999 F, et il manque un franc à chaque commande.
            $nb = count($parBoutique);
            $fraisPart = intdiv($frais, $nb);
            $reste = $frais - $fraisPart * $nb;
            $premier = true;
            $totalTtc = 0; $totalTva = 0;

            foreach ($parBoutique as $boutiqueId => $lignes) {
                $boutique = Boutique::with('pays')->find($boutiqueId);
                $montant = 0; $tva = 0;
                $assujettie = $this->taxes->venteAssujettie($boutique);

                foreach ($lignes as $l) {
                    $montant += $l['variante']->prixTtc() * $l['quantite'];
                }

                if ($assujettie) {
                    $taux = $this->taxes->taux($boutique->pays,
                        $lignes[0]['variante']->produit->categorie->code_taxe);
                    $tva = $this->taxes->ventiler($montant, $taux)['tva'];
                }

                // Ordre des prélèvements : commission d'abord (elle porte
                // sur le montant de la vente), retenue à la source ensuite
                // (elle porte sur la même base, pas sur le net).
                $tauxCom = (float) $boutique->taux_commission;
                $commission = (int) round($montant * $tauxCom / 100);
                $tauxRet = $this->retenues->taux($boutique, $montant);
                $retenue = $this->retenues->calculer($boutique, $montant);

                $sc = $commande->sousCommandes()->create([
                    'boutique_id'              => $boutiqueId,
                    'reference'                => $commande->reference . '-' . $boutique->code,
                    'statut'                   => 'a_preparer',
                    'etat_fonds'               => $etatFonds,
                    'montant_articles_ttc_cfa' => $montant,
                    'montant_tva_cfa'          => $tva,
                    'frais_livraison_cfa'      => $fraisPart + ($premier ? $reste : 0),
                    'taux_commission_pct'      => $tauxCom,
                    'commission_cfa'           => $commission,
                    'taux_retenue_source_pct'  => $tauxRet,
                    'retenue_source_cfa'       => $retenue,
                    // Les frais de livraison ne reviennent pas au
                    // vendeur : ils paient le livreur.
                    'montant_net_cfa'          => $montant - $commission - $retenue,
                ]);
                $premier = false;

                foreach ($lignes as $l) {
                    $v = $l['variante'];
                    $prix = $v->prixTtc();
                    $sc->lignes()->create([
                        'variante_id'           => $v->id,
                        'sku'                   => $v->sku,
                        'nom_produit'           => $v->produit->nom,
                        'libelle_variante'      => $v->libelle,
                        'prix_unitaire_ttc_cfa' => $prix,
                        'taux_tva_pct'          => $assujettie ? ($taux ?? 0) : 0,
                        'quantite'              => $l['quantite'],
                        'total_ttc_cfa'         => $prix * $l['quantite'],
                        'total_tva_cfa'         => 0,
                    ]);
                    $v->decrement('stock', $l['quantite']);
                }

                $sc->journaliser('creee', ['montant' => $montant, 'mode' => $client['mode_paiement']]);
                $totalTtc += $montant; $totalTva += $tva;
            }

            $commande->update([
                'total_articles_ttc_cfa' => $totalTtc,
                'total_tva_cfa'          => $totalTva,
                'total_a_payer_cfa'      => $totalTtc + $frais,
            ]);

            return $commande->fresh('sousCommandes.lignes');
        });
    }
}
