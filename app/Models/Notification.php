<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $table = 'notifications';
    protected $guarded = ['id'];
    public $timestamps = false;

    /**
     * Le texte envoyé contient des codes de livraison, de retrait et
     * d'activation : chiffré au repos. Le code lit toujours la valeur
     * en clair via le modèle ; seule la base ne la voit plus.
     */
    protected function casts(): array
    {
        return ['corps_envoye' => 'encrypted'];
    }
}
