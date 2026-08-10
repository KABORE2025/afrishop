<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemiseTransitaire extends Model
{
    protected $table = 'remises_transitaire';
    protected $guarded = ['id'];
    public $timestamps = false;

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }
}
