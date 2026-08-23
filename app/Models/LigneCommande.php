<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LigneCommande extends Model
{
    protected $table = 'lignes_commande';
    protected $guarded = ['id'];
    public $timestamps = false;

    /**
     * Nullable : une variante peut être retirée du catalogue après coup,
     * la ligne de commande garde son historique (nom, prix recopiés).
     */
    public function variante(): BelongsTo
    {
        return $this->belongsTo(VarianteProduit::class, 'variante_id');
    }
}
