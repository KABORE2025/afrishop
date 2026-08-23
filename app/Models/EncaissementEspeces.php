<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EncaissementEspeces extends Model
{
    protected $table = 'encaissements_especes';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected function casts(): array
    {
        return ['remis_le' => 'datetime'];
    }

    public function expedition(): BelongsTo { return $this->belongsTo(Expedition::class); }
    public function livreur(): BelongsTo    { return $this->belongsTo(Utilisateur::class, 'livreur_id'); }
}
