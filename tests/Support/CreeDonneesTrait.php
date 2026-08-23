<?php

namespace Tests\Support;

use App\Models\Boutique;
use App\Models\Categorie;
use App\Models\Pays;
use App\Models\Produit;
use App\Models\TauxTaxe;
use App\Models\Utilisateur;
use App\Models\VarianteProduit;
use App\Models\Ville;
use App\Models\ZoneLivraison;
use Illuminate\Support\Str;

/**
 * Fabrique le minimum de données de référence nécessaires pour exercer
 * le parcours commande/vendeur en test, sans dépendre des gros dumps SQL
 * de démonstration de database/seeders/ (données de démo, pas des
 * fixtures de test : trop volumineuses, pas versionnées pour ça).
 */
trait CreeDonneesTrait
{
    protected function creerPays(array $attrs = []): Pays
    {
        return Pays::create(array_merge([
            'code_iso2'                           => 'BF',
            'nom'                                  => 'Burkina Faso',
            'devise'                               => 'XOF',
            'indicatif_telephonique'               => '226',
            'retenue_source_non_immatricule_pct'   => 25,
            'retenue_source_immatricule_pct'       => 5,
            'retenue_source_seuil_cfa'             => 5000,
            'actif'                                => true,
        ], $attrs));
    }

    protected function creerVille(Pays $pays, array $attrs = []): Ville
    {
        return Ville::create(array_merge([
            'pays_id' => $pays->id,
            'nom'     => 'Ouagadougou',
            'slug'    => 'ouagadougou',
            'active'  => true,
        ], $attrs));
    }

    protected function creerZoneLivraison(Pays $pays, ?Ville $ville = null, array $attrs = []): ZoneLivraison
    {
        return ZoneLivraison::create(array_merge([
            'pays_id'                     => $pays->id,
            'ville_id'                    => $ville?->id,
            'frais_base_cfa'              => 1500,
            'frais_boutique_sup_cfa'      => 900,
            'paiement_livraison_autorise' => true,
            'active'                      => true,
        ], $attrs));
    }

    protected function creerTauxTva(Pays $pays, array $attrs = []): TauxTaxe
    {
        return TauxTaxe::create(array_merge([
            'pays_id'    => $pays->id,
            'code'       => 'tva_normal',
            'libelle'    => 'TVA normale',
            'taux_pct'   => 18,
            'date_debut' => '2020-01-01',
        ], $attrs));
    }

    protected function creerCategorie(array $attrs = []): Categorie
    {
        return Categorie::create(array_merge([
            'nom'    => 'Alimentation',
            'slug'   => 'alimentation-' . Str::random(6),
            'active' => true,
        ], $attrs));
    }

    protected function creerUtilisateur(Pays $pays, array $attrs = []): Utilisateur
    {
        return Utilisateur::create(array_merge([
            'pays_id'      => $pays->id,
            'nom'          => 'Aïcha Traoré',
            'telephone'    => '2267' . random_int(1000000, 9999999),
            'mot_de_passe' => 'mot-de-passe-sur',
            'role'         => 'client',
        ], $attrs));
    }

    /** @return array{boutique: Boutique, utilisateur: Utilisateur} */
    protected function creerBoutiqueAvecVendeur(Pays $pays, array $attrsBoutique = [], array $attrsUtilisateur = []): array
    {
        $utilisateur = $this->creerUtilisateur($pays, array_merge(['role' => 'vendeur'], $attrsUtilisateur));

        $boutique = Boutique::create(array_merge([
            'utilisateur_id'      => $utilisateur->id,
            'pays_id'             => $pays->id,
            'code'                => $pays->code_iso2 . '-V' . random_int(100, 999),
            'nom'                 => 'Karité du Sahel',
            'slug'                => 'karite-du-sahel-' . Str::random(6),
            'telephone'           => $utilisateur->telephone,
            'taux_commission'     => 12,
            'statut'              => 'actif',
            'paiement_numero'     => $utilisateur->telephone,
            'paiement_verifie_le' => now(),
        ], $attrsBoutique));

        return ['boutique' => $boutique, 'utilisateur' => $utilisateur];
    }

    /** @return array{produit: Produit, variante: VarianteProduit} */
    protected function creerProduitAvecVariante(
        Boutique $boutique, Categorie $categorie, array $attrsProduit = [], array $attrsVariante = []
    ): array {
        $nom = $attrsProduit['nom'] ?? 'Beurre de karité brut 500 g';

        $produit = Produit::create(array_merge([
            'boutique_id'       => $boutique->id,
            'categorie_id'      => $categorie->id,
            'reference'         => Produit::prochaineReference($boutique),
            'nom'               => $nom,
            'slug'              => Produit::slugUnique($nom),
            'prix_ttc_cfa'      => 3000,
            'actif'             => true,
            'statut_moderation' => 'publie',
        ], $attrsProduit));

        $variante = $produit->variantes()->create(array_merge([
            'sku'     => $produit->reference . '-V1',
            'libelle' => 'Standard',
            'stock'   => 50,
            'defaut'  => true,
            'actif'   => true,
        ], $attrsVariante));

        return ['produit' => $produit, 'variante' => $variante];
    }
}
