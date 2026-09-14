<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRemiseBoutique extends Model
{
    protected $table = 'agents_remise_boutiques';
    public const CREATED_AT = 'cree_le';
    public const UPDATED_AT = 'modifie_le';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'autorise_livraison' => 'boolean',
            'autorise_retrait_boutique' => 'boolean',
            'invitation_expire_le' => 'datetime',
            'accepte_le' => 'datetime',
        ];
    }

    public function livreur(): BelongsTo { return $this->belongsTo(Utilisateur::class, 'livreur_id'); }
    public function boutique(): BelongsTo { return $this->belongsTo(Boutique::class); }
}
