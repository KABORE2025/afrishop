<?php

namespace App\Services;

use App\Models\VarianteProduit;
use Illuminate\Support\Collection;

/**
 * =====================================================================
 *  LE PANIER, EN SESSION
 * =====================================================================
 *  POURQUOI PAS DE JAVASCRIPT, ET POURQUOI PAS EN BASE.
 *
 *  Pas de JavaScript : la vitrine tient sa promesse — première page
 *  utile en moins de trois secondes en 2G, sous 300 Ko. Un panier
 *  piloté par le navigateur imposerait de charger un bundle avant le
 *  premier ajout, et échouerait précisément là où ce site doit marcher.
 *  Chaque bouton est donc un formulaire qui recharge la page. C'est un
 *  aller-retour de plus, et c'est infiniment plus robuste.
 *
 *  Pas en base : un visiteur anonyme qui parcourt le catalogue n'a pas
 *  à créer des lignes en base. La table `paniers` du modèle reste utile,
 *  mais pour ce qu'elle sait faire de mieux — la relance des paniers
 *  abandonnés — et cela demande d'abord un moyen de recontacter le
 *  client. Tant qu'on ne l'a pas, écrire en base ne servirait qu'à
 *  remplir le disque.
 *
 *  LE PANIER NE STOCKE QUE DES IDENTIFIANTS ET DES QUANTITÉS.
 *  Ni prix, ni nom, ni stock. Tout est relu en base à l'affichage : un
 *  prix recopié en session serait le prix d'hier, et le client
 *  découvrirait l'écart au paiement — la meilleure façon de perdre une
 *  vente et la confiance en même temps.
 * =====================================================================
 */
class PanierSession
{
    private const CLE = 'panier';

    /** Contenu brut : [ variante_id => quantite ]. */
    public function lignes(): array
    {
        return session(self::CLE, []);
    }

    public function estVide(): bool
    {
        return $this->lignes() === [];
    }

    /** Nombre d'articles, toutes lignes confondues. */
    public function nombreArticles(): int
    {
        return array_sum($this->lignes());
    }

    /**
     * Ajoute une quantité. Une seconde mise au panier du même article
     * INCRÉMENTE au lieu d'empiler une ligne — même règle que
     * `lignes_panier` dans le modèle.
     */
    public function ajouter(int $varianteId, int $quantite = 1): void
    {
        $lignes = $this->lignes();
        $lignes[$varianteId] = ($lignes[$varianteId] ?? 0) + max(1, $quantite);

        session([self::CLE => $lignes]);
    }

    /** Quantité absolue. Zéro retire la ligne. */
    public function definir(int $varianteId, int $quantite): void
    {
        $lignes = $this->lignes();

        if ($quantite <= 0) {
            unset($lignes[$varianteId]);
        } else {
            $lignes[$varianteId] = $quantite;
        }

        session([self::CLE => $lignes]);
    }

    public function retirer(int $varianteId): void
    {
        $this->definir($varianteId, 0);
    }

    public function vider(): void
    {
        session()->forget(self::CLE);
    }

    /**
     * Le panier détaillé, relu en base.
     *
     * Renvoie une collection de lignes prêtes à afficher, groupées par
     * boutique — parce qu'un panier multi-boutiques produit plusieurs
     * colis, donc plusieurs frais de livraison, et que le client doit
     * le comprendre AVANT de payer.
     *
     * @return array{lignes: Collection, par_boutique: Collection, total_articles_cfa: int, alertes: array}
     */
    public function detail(): array
    {
        $lignes = $this->lignes();

        if ($lignes === []) {
            return ['lignes' => collect(), 'par_boutique' => collect(),
                    'total_articles_cfa' => 0, 'alertes' => []];
        }

        $variantes = VarianteProduit::whereIn('id', array_keys($lignes))
            ->with(['produit.boutique', 'produit.categorie', 'produit.medias'])
            ->get()->keyBy('id');

        $alertes = [];
        $detail  = collect();

        foreach ($lignes as $varianteId => $quantite) {
            $v = $variantes->get($varianteId);

            /*
             * L'article a disparu depuis la mise au panier : produit
             * retiré, boutique fermée, variante désactivée. On le sort
             * du panier et on le DIT. Un article qui s'évapore en
             * silence fait croire à un bogue.
             */
            if (! $v || ! $v->actif || ! $v->produit->actif
                || $v->produit->statut_moderation !== 'publie') {
                $this->retirer($varianteId);
                $alertes[] = $v
                    ? "« {$v->produit->nom} » n'est plus en vente : l'article a été retiré du panier."
                    : "Un article n'est plus disponible : il a été retiré du panier.";
                continue;
            }

            // Stock devenu insuffisant : on rabote plutôt que de vider.
            if ($v->stock < $quantite) {
                $alertes[] = $v->stock > 0
                    ? "« {$v->produit->nom} » : il n'en reste que {$v->stock}. La quantité a été ajustée."
                    : "« {$v->produit->nom} » est en rupture : l'article a été retiré du panier.";

                $this->definir($varianteId, $v->stock);
                $quantite = $v->stock;

                if ($quantite === 0) {
                    continue;
                }
            }

            $prix = $v->prixTtc();

            $detail->push([
                'variante'   => $v,
                'produit'    => $v->produit,
                'boutique'   => $v->produit->boutique,
                'quantite'   => $quantite,
                'prix_cfa'   => $prix,
                'total_cfa'  => $prix * $quantite,
            ]);
        }

        return [
            'lignes'             => $detail,
            'par_boutique'       => $detail->groupBy(fn ($l) => $l['boutique']->id),
            'total_articles_cfa' => (int) $detail->sum('total_cfa'),
            'alertes'            => $alertes,
        ];
    }

    /** Format attendu par PanierService::creerCommande(). */
    public function articlesPourCommande(): array
    {
        return collect($this->lignes())
            ->map(fn ($q, $id) => ['variante_id' => (int) $id, 'quantite' => (int) $q])
            ->values()->all();
    }
}
