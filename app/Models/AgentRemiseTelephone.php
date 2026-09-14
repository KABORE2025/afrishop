<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRemiseTelephone extends Model
{
    protected $table = 'agent_remise_telephones';
    public const CREATED_AT = 'cree_le';
    public const UPDATED_AT = null;
    protected $guarded = ['id'];

    protected function casts(): array { return ['est_principal' => 'boolean', 'verifie_le' => 'datetime']; }
    public function livreur(): BelongsTo { return $this->belongsTo(Utilisateur::class, 'livreur_id'); }
}
