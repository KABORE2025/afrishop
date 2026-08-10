<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Famille de prestation : BTP, génie logiciel, formation, juridique.
 *
 * `profession_reglementee` n'est pas décoratif : quand il est vrai, la
 * plateforme référence et met en relation, mais N'ENCAISSE PAS. Un
 * notaire manie des fonds de tiers dont il répond personnellement
 * devant sa chambre ; on ne s'y interpose pas.
 */
class FamilleService extends Model
{
    protected $table = 'familles_service';
    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'devis_obligatoire'      => 'boolean',
        'profession_reglementee' => 'boolean',
    ];

    public function services()
    {
        return $this->hasMany(Service::class, 'famille_id');
    }
}
