<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Professionnel de l'annuaire : notaire, avocat, huissier,
 * tradipraticien.
 *
 * AUCUNE CLÉ ÉTRANGÈRE NE RELIE CETTE TABLE AUX COMMANDES, et c'est
 * intentionnel : la relation n'existe pas et ne doit pas pouvoir être
 * créée par mégarde. Afrishop vérifie et met en relation ; il
 * n'encaisse aucun honoraire.
 */
class Professionnel extends Model
{
    protected $table = 'professionnels';
    protected $guarded = ['id'];

    protected $casts = [
        'actes'                  => 'array',
        'verifie_le'             => 'date',
        'verification_expire_le' => 'date',
        'avertissement_sante'    => 'boolean',
        'publie'                 => 'boolean',
    ];

    public function pays()
    {
        return $this->belongsTo(Pays::class, 'pays_id');
    }

    /**
     * Une vérification a une date de fin. Sans elle, « vérifié » devient
     * un mensonge le jour où le professionnel est radié — et c'est la
     * plateforme qui l'aura affirmé.
     */
    public function verificationValide(): bool
    {
        return $this->verifie_le !== null
            && $this->verification_expire_le !== null
            && $this->verification_expire_le->isFuture();
    }
}
