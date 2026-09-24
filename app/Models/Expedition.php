<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Expedition extends Model
{
    protected $table = 'expeditions';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'expedie_le'     => 'datetime',
            'livre_le'       => 'datetime',
            'code_valide_le' => 'datetime',
            // Chiffré au repos : ce code libère l'argent de la boutique.
            'code_livraison' => 'encrypted',
        ];
    }

    /** Jamais sérialisé : seul l'admin le lit, explicitement, avec motif. */
    protected $hidden = ['code_livraison', 'tentatives'];

    public function sousCommande(): BelongsTo { return $this->belongsTo(SousCommande::class); }
    public function livreur(): BelongsTo      { return $this->belongsTo(Utilisateur::class, 'livreur_id'); }
    public function transporteur(): BelongsTo { return $this->belongsTo(Transporteur::class); }
    public function encaissementEspeces(): HasOne { return $this->hasOne(EncaissementEspeces::class); }
}
