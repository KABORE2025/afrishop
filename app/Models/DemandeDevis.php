<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Demande de chiffrage. Elle n'engage à rien : aucun paiement n'est
 * demandé tant qu'un devis chiffré n'a pas été accepté.
 *
 * Une demande sans suite est une vente perdue qu'on n'aurait jamais vue
 * sans cette table.
 */
class DemandeDevis extends Model
{
    protected $table = 'demandes_devis';
    protected $guarded = ['id'];

    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function boutique()
    {
        return $this->belongsTo(Boutique::class, 'boutique_id');
    }

    public function devis()
    {
        return $this->hasMany(Devis::class, 'demande_id');
    }
}
