<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un pays de l'UEMOA. Porte tout ce qui varie d'un marché à l'autre.
 *
 * Rappel utile : les 8 pays partagent le franc CFA. Le multi-pays ne
 * crée donc pas de multi-devise — pas de table de change, pas de
 * conversion, pas d'arrondi de conversion. Ce qui varie, c'est la
 * fiscalité, les opérateurs de paiement et le droit de la consommation.
 */
class Pays extends Model
{
    protected $table = 'pays';
    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'facture_certifiee_obligatoire' => 'boolean',
            'transfert_donnees_autorisation_requise' => 'boolean',
            'ouvert_le' => 'date',
        ];
    }

    public function villes(): HasMany     { return $this->hasMany(Ville::class); }
    public function operateurs(): HasMany { return $this->hasMany(OperateurPaiement::class); }
    public function taux(): HasMany       { return $this->hasMany(TauxTaxe::class); }
    public function boutiques(): HasMany  { return $this->hasMany(Boutique::class); }

    /**
     * Ce pays impose-t-il une facture certifiée par l'État ?
     * Si oui, la plateforme ne peut pas auto-facturer pour un vendeur
     * non enrôlé : à trancher avec un fiscaliste avant l'ouverture.
     */
    public function exigeFactureCertifiee(): bool
    {
        return $this->facture_certifiee_obligatoire;
    }

    /** Date limite de rétractation à partir d'une livraison. */
    public function limiteRetractation(\DateTimeInterface $livraison, bool $informationDonnee = true): ?\Carbon\CarbonInterface
    {
        $jours = $informationDonnee
            ? $this->retractation_jours_ouvrables
            : ($this->retractation_jours_si_defaut_info ?? $this->retractation_jours_ouvrables);

        return $jours === null ? null : \Carbon\Carbon::instance($livraison)->addWeekdays($jours);
    }
}
