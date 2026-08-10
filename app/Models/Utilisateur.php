<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Compte unique, quel que soit le rôle.
 * L'identifiant de connexion est le TÉLÉPHONE, pas l'e-mail.
 */
class Utilisateur extends Authenticatable
{
    use HasApiTokens, SoftDeletes;

    protected $table = 'utilisateurs';
    public const CREATED_AT = 'cree_le';
    public const UPDATED_AT = 'modifie_le';
    public const DELETED_AT = 'supprime_le';

    protected $guarded = ['id'];
    protected $hidden  = ['mot_de_passe'];

    /** Laravel attend « password » ; notre colonne s'appelle « mot_de_passe ». */
    public function getAuthPassword(): string
    {
        return $this->mot_de_passe;
    }

    protected function casts(): array
    {
        return [
            'mot_de_passe'         => 'hashed',
            'telephone_verifie_le' => 'datetime',
            'derniere_connexion'   => 'datetime',
        ];
    }

    public function boutique(): HasOne { return $this->hasOne(Boutique::class); }

    public function pays()
    {
        return $this->belongsTo(Pays::class);
    }

    /**
     * Un portefeuille non identifié est plafonné à 200 000 FCFA par mois
     * (instruction BCEAO 008-05-2015). Un vendeur doit donc être poussé
     * vers une identification complète avant d'être payable.
     */
    public function plafondMensuelCfa(): int
    {
        return $this->kyc_niveau === 'aucun'
            ? (int) parametre('plafond_wallet_non_identifie_mensuel_cfa', 200_000)
            : (int) parametre('plafond_wallet_identifie_cfa', 2_000_000);
    }

    public function estAdmin(): bool  { return in_array($this->role, ['admin', 'agent'], true); }
    public function estVendeur(): bool { return $this->role === 'vendeur'; }
}
