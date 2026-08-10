<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tranche du barème dégressif. `plafond_cfa` à NULL = tranche
 * supérieure, sans plafond.
 *
 * Le calcul se fait comme un impôt sur le revenu : chaque tranche à son
 * taux. Un taux unique choisi par palier ferait BAISSER le revenu net
 * en franchissant un seuil, et inciterait à découper un chantier en
 * deux devis. Un barème qui récompense la fraude est un barème raté.
 */
class BaremeCommission extends Model
{
    protected $table = 'bareme_commission';
    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'en_vigueur_du' => 'date',
        'en_vigueur_au' => 'date',
    ];
}
