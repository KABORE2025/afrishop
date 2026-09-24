<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Litige extends Model
{
    protected $table = 'litiges';
    protected $guarded = ['id'];
    public $timestamps = false;

    public const MOTIFS = ['non_recu', 'endommage', 'non_conforme', 'incomplet', 'contrefacon', 'autre'];

    public const PHOTOS_MAX = 2;

    public function sousCommande(): BelongsTo { return $this->belongsTo(SousCommande::class); }

    /** Photos jointes par le client — stockées hors du dossier public. */
    public function photos(): HasMany
    {
        return $this->hasMany(Media::class, 'proprietaire_id')
            ->where('proprietaire_type', 'litige')->orderBy('ordre');
    }

    public function estOuvert(): bool
    {
        return in_array($this->statut, ['ouvert', 'en_examen'], true);
    }
}
