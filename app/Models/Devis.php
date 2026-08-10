<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Devis chiffré. Le devis ACCEPTÉ vaut contrat.
 *
 * La commission est FIGÉE ici, pas recalculée à l'affichage : si le
 * barème change demain, un devis déjà émis ne doit pas changer de
 * commission — ni au détriment du prestataire, ni au vôtre.
 */
class Devis extends Model
{
    protected $table = 'devis';
    protected $guarded = ['id'];

    protected $casts = [
        'valable_jusqu_au' => 'date',
        'accepte_le'       => 'datetime',
    ];

    public function demande()
    {
        return $this->belongsTo(DemandeDevis::class, 'demande_id');
    }

    public function jalons()
    {
        return $this->hasMany(Jalon::class, 'devis_id')->orderBy('ordre');
    }

    /**
     * Ce que la plateforme détient RÉELLEMENT à cet instant.
     *
     * C'est ce chiffre — et non le montant du contrat — qui mesure le
     * risque porté. Sur un chantier de 25 millions découpé en quatre
     * tranches, il plafonne à la plus grosse tranche.
     */
    public function expositionCourante(): int
    {
        return (int) $this->jalons()->where('etat_fonds', 'sequestre')->sum('montant_cfa');
    }
}
