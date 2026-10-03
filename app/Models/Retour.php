<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Retour extends Model
{
    protected $table = 'retours';
    protected $guarded = ['id'];
    public $timestamps = false;

    public const MOTIFS = ['ne_convient_pas', 'taille_incorrecte', 'erreur_commande', 'autre'];

    protected function casts(): array
    {
        return ['demande_le' => 'datetime', 'traite_le' => 'datetime', 'recu_le' => 'datetime'];
    }

    public function sousCommande(): BelongsTo { return $this->belongsTo(SousCommande::class); }
}
