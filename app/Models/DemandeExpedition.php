<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandeExpedition extends Model
{
    protected $table = 'demandes_expedition';
    protected $guarded = ['id'];
    public $timestamps = false;

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }
}
