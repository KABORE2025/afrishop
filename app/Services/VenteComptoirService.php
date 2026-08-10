<?php

namespace App\Services;

use App\Models\Boutique;
use App\Models\Commande;
use App\Models\MouvementStock;
use App\Models\VarianteProduit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * =====================================================================
 *  VENTE AU COMPTOIR
 * =====================================================================
 *  Le client entre dans l'atelier, choisit un pot de karité, paie en
 *  liquide, repart. C'est probablement la majorité du chiffre
 *  d'affaires réel des artisans, et ça n'existait pas dans le modèle.
 *
 *  POURQUOI L'ENREGISTRER ALORS QUE L'ARGENT NE NOUS CONCERNE PAS :
 *
 *   · Le stock en ligne devient faux sans cela. Le vendeur écoule au
 *     marché ce que le site affiche disponible, et un client commande
 *     un produit qui n'existe plus. C'est la première cause de litige
 *     évitable.
 *   · Le vendeur n'a aucune raison d'ouvrir l'application chaque jour
 *     si elle ne sert qu'aux rares commandes en ligne. Une plateforme
 *     qu'on ouvre une fois par semaine finit par ne plus être ouverte.
 *   · L'historique, la réputation et la traçabilité QR ne couvriraient
 *     qu'une fraction de l'activité.
 *
 *  CE QUI DIFFÈRE RADICALEMENT D'UNE VENTE EN LIGNE :
 *
 *   · Aucune écriture au GRAND LIVRE. L'argent n'a jamais transité par
 *     la plateforme : il n'y a rien à séquestrer, rien à reverser,
 *     rien à cantonner. L'état des fonds est « hors_plateforme ».
 *   · Aucune livraison, aucun litige possible : le client est reparti
 *     avec son achat.
 *   · Commission NULLE par défaut. C'est un choix délibéré : avec une
 *     commission sur le comptoir, aucun vendeur ne déclarerait ses
 *     ventes présentielles, et le stock resterait faux — ce qui coûte
 *     bien plus cher que la commission perdue.
 * =====================================================================
 */
class VenteComptoirService
{
    /**
     * Enregistre une vente réalisée en présentiel.
     *
     * @param  array<int, array{variante_id:int, quantite:int}>  $articles
     * @param  string  $modePaiement  especes_comptoir | mobile_money_comptoir
     */
    public function enregistrer(Boutique $boutique, array $articles, string $modePaiement = 'especes_comptoir',
                                ?string $clientNom = null, ?string $clientTelephone = null,
                                ?int $auteurId = null): Commande
    {
        if (! $boutique->vend_au_comptoir) {
            throw new RuntimeException("La boutique « {$boutique->nom} » n'est pas configurée pour la vente au comptoir.");
        }
        if ($articles === []) {
            throw new RuntimeException('Aucun article dans la vente.');
        }

        return DB::transaction(function () use ($boutique, $articles, $modePaiement, $clientNom, $clientTelephone, $auteurId) {

            $variantes = VarianteProduit::whereIn('id', array_column($articles, 'variante_id'))
                ->with('produit')->lockForUpdate()->get()->keyBy('id');

            $total = 0;
            foreach ($articles as $a) {
                $v = $variantes->get($a['variante_id']) ?? throw new RuntimeException('Article introuvable.');

                if ($v->produit->boutique_id !== $boutique->id) {
                    throw new RuntimeException("« {$v->produit->nom} » n'appartient pas à cette boutique.");
                }
                // On avertit sans bloquer : le vendeur voit sa
                // marchandise devant lui, c'est le compteur qui a tort,
                // pas la réalité. Bloquer la vente ferait perdre le
                // client et n'apprendrait rien au système.
                if ($v->stock < $a['quantite']) {
                    logger()->warning('Vente au comptoir en stock négatif', [
                        'sku' => $v->sku, 'stock' => $v->stock, 'vendu' => $a['quantite'],
                    ]);
                }
                $total += $v->prixTtc() * $a['quantite'];
            }

            // Commission sur une vente que la plateforme n'a pas
            // apportée : nulle par défaut, mais négociable par boutique.
            $tauxCom = (float) $boutique->taux_commission_comptoir;
            $commission = (int) round($total * $tauxCom / 100);

            $commande = Commande::create([
                'reference'              => Commande::prochaineReference($boutique->pays),
                'pays_id'                => $boutique->pays_id,
                'client_nom'             => $clientNom ?? 'Client comptoir',
                'client_telephone'       => $clientTelephone ?? '',
                'mode_livraison'         => 'emporte',
                'mode_paiement'          => $modePaiement,
                'statut_paiement'        => 'encaisse',
                'statut'                 => 'livree',
                'canal'                  => 'comptoir',
                'total_articles_ttc_cfa' => $total,
                'total_a_payer_cfa'      => $total,
                'confirmee_le'           => now(),
            ]);

            $sc = $commande->sousCommandes()->create([
                'boutique_id'              => $boutique->id,
                'reference'                => $commande->reference . '-' . $boutique->code,
                'statut'                   => 'vendue_comptoir',
                'vente_comptoir'           => true,
                // L'état qui dit tout : cet argent n'est jamais passé
                // par nous, il n'y a donc rien à libérer ni à verser.
                'etat_fonds'               => 'hors_plateforme',
                'montant_articles_ttc_cfa' => $total,
                'taux_commission_pct'      => $tauxCom,
                'commission_cfa'           => $commission,
                'taux_retenue_source_pct'  => 0,
                'retenue_source_cfa'       => 0,
                'montant_net_cfa'          => $total - $commission,
                'livre_le'                 => now(),
            ]);

            foreach ($articles as $a) {
                $v = $variantes[$a['variante_id']];
                $sc->lignes()->create([
                    'variante_id'           => $v->id,
                    'sku'                   => $v->sku,
                    'nom_produit'           => $v->produit->nom,
                    'libelle_variante'      => $v->libelle,
                    'prix_unitaire_ttc_cfa' => $v->prixTtc(),
                    'quantite'              => $a['quantite'],
                    'total_ttc_cfa'         => $v->prixTtc() * $a['quantite'],
                ]);

                $this->sortirDuStock($v, $a['quantite'], 'vente_comptoir', 'sous_commande', $sc->id, $auteurId);
            }

            $sc->journaliser('vente_comptoir', [
                'montant'    => $total,
                'commission' => $commission,
                'mode'       => $modePaiement,
            ], $auteurId, 'vendeur');

            return $commande->fresh('sousCommandes.lignes');
        });
    }

    /**
     * Décrémente le stock ET journalise pourquoi.
     *
     * Sans ce journal, on constate un écart d'inventaire sans jamais
     * savoir d'où il vient. Avec lui, chaque variation a une cause,
     * une pièce et un auteur.
     */
    public function sortirDuStock(VarianteProduit $v, int $quantite, string $type,
                                  ?string $pieceType = null, ?int $pieceId = null,
                                  ?int $auteurId = null, ?string $motif = null): MouvementStock
    {
        $avant = $v->stock;
        $v->decrement('stock', $quantite);

        return MouvementStock::create([
            'variante_id' => $v->id,
            'type'        => $type,
            'quantite'    => -$quantite,
            'stock_avant' => $avant,
            'stock_apres' => $avant - $quantite,
            'piece_type'  => $pieceType,
            'piece_id'    => $pieceId,
            'motif'       => $motif,
            'auteur_id'   => $auteurId,
        ]);
    }

    /**
     * Ajustement d'inventaire. Le vendeur compte ce qu'il a en rayon
     * et corrige : c'est le moment où l'on rattrape les ventes non
     * déclarées, la casse et les erreurs de saisie.
     */
    public function ajusterInventaire(VarianteProduit $v, int $stockReel, string $motif, ?int $auteurId = null): MouvementStock
    {
        $avant = $v->stock;
        $v->update(['stock' => $stockReel]);

        return MouvementStock::create([
            'variante_id' => $v->id,
            'type'        => 'inventaire',
            'quantite'    => $stockReel - $avant,
            'stock_avant' => $avant,
            'stock_apres' => $stockReel,
            'motif'       => $motif,
            'auteur_id'   => $auteurId,
        ]);
    }
}
