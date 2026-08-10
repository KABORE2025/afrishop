<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Une prestation. Ce n'est PAS un produit sans stock : il n'y a ni
 * stock, ni livraison, ni QR, ni — souvent — de prix ferme.
 *
 * Le mode de vente n'est pas une préférence de la plateforme mais une
 * propriété du service : une formation à 75 000 F se met au panier, une
 * maison R+1 ne s'achète pas en un clic. Imposer l'un des deux partout
 * perdrait la moitié du catalogue.
 */
class Service extends Model
{
    protected $table = 'services';
    protected $guarded = ['id'];

    protected $casts = ['actif' => 'boolean'];

    public function boutique()
    {
        return $this->belongsTo(Boutique::class, 'boutique_id');
    }

    public function famille()
    {
        return $this->belongsTo(FamilleService::class, 'famille_id');
    }

    public function jalonsType()
    {
        return $this->hasMany(JalonType::class, 'service_id');
    }

    public function demandes()
    {
        return $this->hasMany(DemandeDevis::class, 'service_id');
    }

    public function surDevis(): bool
    {
        return $this->mode_vente === 'devis';
    }

    /**
     * Montant de référence pour afficher les jalons et la commission
     * avant tout chiffrage : le milieu de la fourchette en mode devis,
     * le prix ferme sinon.
     */
    public function montantIndicatif(): int
    {
        return $this->surDevis()
            ? (int) round(($this->fourchette_min_cfa + $this->fourchette_max_cfa) / 2)
            : (int) $this->prix_cfa;
    }

    /**
     * L'ENSEMBLE des mots indexés — pas la concaténation du texte.
     * Comparer des mots entiers évite qu'une recherche sur « da »
     * remonte « syscohada » et « bazin damassé ».
     */
    public function motsIndexes(): array
    {
        $r = app(\App\Services\RechercheService::class);

        return array_values(array_unique($r->mots(
            $this->nom . ' ' . $this->description . ' '
            . ($this->famille->libelle ?? '') . ' ' . ($this->boutique->nom ?? '')
        )));
    }
}
