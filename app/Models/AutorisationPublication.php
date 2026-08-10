<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Autorisation de publier une fiche en catégorie réservée.
 *
 * LE SILENCE VAUT REFUS : sans ligne « accorde », la fiche reste
 * invisible. C'est l'inverse du réflexe « publier puis modérer », et
 * c'est volontaire — une fiche fautive vue une heure a déjà produit son
 * effet, et le retrait ne défait pas la lecture.
 */
class AutorisationPublication extends Model
{
    protected $table = 'autorisations_publication';
    protected $guarded = ['id'];

    protected $casts = [
        'termes_signales' => 'array',
        'demande_le'      => 'datetime',
        'decide_le'       => 'datetime',
    ];

    public function produit()
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }
}
