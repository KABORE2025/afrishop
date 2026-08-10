<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Une tranche de paiement. C'est la pièce qui rend les services
 * tenables : on ne séquestre JAMAIS le contrat entier, seulement le
 * jalon en cours.
 *
 * Le vocabulaire des fonds est le même que celui des sous-commandes.
 * Deux vocabulaires pour une même notion finiraient par diverger.
 */
class Jalon extends Model
{
    protected $table = 'jalons';
    protected $guarded = ['id'];

    protected $casts = [
        'appele_le' => 'datetime',
        'valide_le' => 'datetime',
    ];

    public function devis()
    {
        return $this->belongsTo(Devis::class, 'devis_id');
    }
}
