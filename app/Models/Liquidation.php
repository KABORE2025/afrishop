<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Une liquidation porte sur une VARIANTE, jamais sur un produit : le pot
 * de 250 g peut être en fin de vie quand le 1 kg vient d'arriver.
 *
 * `prix_reference_cfa` est une COPIE du prix catalogue au moment de la
 * mise en liquidation, pas une lecture. Si le vendeur change son prix
 * demain, le prix barré affiché au client ne doit pas bouger sous ses
 * yeux — sinon la remise annoncée change toute seule.
 */
class Liquidation extends Model
{
    protected $table = 'liquidations';
    protected $guarded = ['id'];

    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    protected $casts = [
        'debut_le'        => 'datetime',
        'fin_le'          => 'datetime',
        'date_peremption' => 'date',
    ];

    public function variante()
    {
        return $this->belongsTo(VarianteProduit::class, 'variante_id');
    }

    public function motif()
    {
        return $this->belongsTo(MotifLiquidation::class, 'motif_id');
    }

    /** Remise affichée, en pourcentage entier. */
    public function remisePct(): int
    {
        return (int) round((1 - $this->prix_liquide_cfa / $this->prix_reference_cfa) * 100);
    }

    /** Le lot a-t-il dépassé sa date limite ? */
    public function estPerime(): bool
    {
        return $this->date_peremption !== null
            && $this->date_peremption->endOfDay()->isPast();
    }
}
