<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Motif d'une liquidation, montré au client.
 *
 * En table et non en ENUM : la liste s'allongera, et un ENUM impose un
 * ALTER TABLE — donc un verrou — pour chaque ajout. Une table se
 * complète avec un INSERT.
 */
class MotifLiquidation extends Model
{
    protected $table = 'motifs_liquidation';
    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = ['date_limite_obligatoire' => 'boolean'];

    public function liquidations()
    {
        return $this->hasMany(Liquidation::class, 'motif_id');
    }
}
