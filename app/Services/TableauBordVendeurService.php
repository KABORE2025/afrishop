<?php

namespace App\Services;

use App\Models\Boutique;
use App\Models\Produit;
use App\Models\Reversement;
use App\Models\SousCommande;
use App\Models\VarianteProduit;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 *  INDICATEURS DE L'ESPACE VENDEUR
 * =====================================================================
 *  POURQUOI CÔTÉ SERVEUR ET PAS CÔTÉ ÉCRAN
 *  La liste des commandes est paginée à 25 lignes. Un front qui
 *  calculerait le chiffre d'affaires à partir de ce qu'il a reçu
 *  afficherait le total des 25 dernières ventes en croyant afficher
 *  celui de la boutique. L'erreur ne se voit pas : le chiffre est
 *  plausible, seulement faux.
 *
 *  LE PIÈGE DE VOCABULAIRE, QUI COMPTE PLUS QUE LE CALCUL
 *  « Chiffre d'affaires » et « argent que je vais toucher » sont deux
 *  choses différentes, et un vendeur qui les confond se croit plus
 *  riche qu'il ne l'est. D'où trois montants distincts et nommés :
 *   · encaissé     — ce que les clients ont payé
 *   · en séquestre — encaissé, à lui, mais pas encore versable
 *   · à recevoir   — libéré, en attente du prochain virement
 *  Aucun écran ne doit les additionner.
 * =====================================================================
 */
class TableauBordVendeurService
{
    public function pour(Boutique $boutique): array
    {
        $base = fn () => SousCommande::where('boutique_id', $boutique->id);

        /*
         * Encaissé = les ventes dont l'argent est réellement entré. Le
         * paiement à la livraison non encore livré (« attente_encaissement »)
         * en est exclu : promettre un chiffre d'affaires sur des colis
         * qui peuvent être refusés à la porte, c'est le surestimer.
         */
        $encaisse = (int) $base()
            ->whereIn('etat_fonds', ['sequestre', 'reverse'])
            ->sum('montant_articles_ttc_cfa');

        $enSequestre = (int) $base()
            ->where('etat_fonds', 'sequestre')
            ->sum('montant_net_cfa');

        /*
         * « reverse » signifie LIBÉRÉ DU SÉQUESTRE, pas « viré sur mon
         * téléphone ». Tant qu'aucun reversement ne l'a pris en charge,
         * l'argent est dû mais pas parti. C'est la question numéro un
         * d'un vendeur, et le modèle n'a pas d'état distinct pour y
         * répondre : on la calcule donc ici, par différence avec les
         * lignes déjà rattachées à un reversement.
         */
        $aRecevoir = (int) $base()
            ->where('etat_fonds', 'reverse')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('reversement_lignes')
                  ->whereColumn('reversement_lignes.sous_commande_id', 'sous_commandes.id');
            })
            ->sum('montant_net_cfa');

        $aTraiter = (int) $base()->whereIn('statut', ['a_preparer', 'prete'])->count();

        /* Panier moyen : sur les ventes réellement encaissées, pour ne
         * pas gonfler la moyenne avec des commandes annulées. */
        $nbEncaissees = (int) $base()->whereIn('etat_fonds', ['sequestre', 'reverse'])->count();
        $panierMoyen  = $nbEncaissees > 0 ? intdiv($encaisse, $nbEncaissees) : 0;

        $produitIds = Produit::where('boutique_id', $boutique->id)->pluck('id');

        return [
            'montants_cfa' => [
                'encaisse'     => $encaisse,
                'en_sequestre' => $enSequestre,
                'a_recevoir'   => $aRecevoir,
                'panier_moyen' => $panierMoyen,
            ],

            'compteurs' => [
                'commandes_a_traiter' => $aTraiter,
                'commandes_expediees' => (int) $base()->where('statut', 'expediee')->count(),
                'litiges_ouverts'     => (int) $base()->whereHas('litiges',
                    fn ($q) => $q->whereIn('statut', ['ouvert', 'en_examen']))->count(),
                'produits_publies'    => (int) Produit::where('boutique_id', $boutique->id)
                    ->where('statut_moderation', 'publie')->count(),
                'produits_en_attente' => (int) Produit::where('boutique_id', $boutique->id)
                    ->where('statut_moderation', 'en_attente')->count(),
            ],

            /*
             * Alerte de stock : ce qui fait perdre une vente sans que
             * personne ne s'en aperçoive. `stock <= 0` compte les
             * ruptures et, le cas échéant, les surventes — le stock est
             * signé en base précisément pour qu'elles restent visibles.
             */
            'stock' => [
                'ruptures' => (int) VarianteProduit::whereIn('produit_id', $produitIds)
                    ->where('actif', true)->where('stock', '<=', 0)->count(),
                'critiques' => (int) VarianteProduit::whereIn('produit_id', $produitIds)
                    ->where('actif', true)->where('stock', '>', 0)
                    ->whereColumn('stock', '<=', 'seuil_alerte')->count(),
            ],

            'dernier_reversement' => Reversement::where('boutique_id', $boutique->id)
                ->orderByDesc('id')->first()?->only(['reference', 'montant_net_cfa', 'statut', 'execute_le']),
        ];
    }
}
