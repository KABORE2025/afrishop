<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Trame de jalons proposée par défaut pour un service. Le devis accepté
 * en fera une COPIE figée : modifier la trame ne doit pas redécouper un
 * chantier en cours.
 */
class JalonType extends Model
{
    protected $table = 'jalons_type';
    protected $guarded = ['id'];

    public $timestamps = false;

    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id');
    }
}
