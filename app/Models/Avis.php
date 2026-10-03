<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un avis client. Seul un acheteur dont le colis a été REMIS peut en
 * laisser un, un seul par produit et par colis (index unique uk_avis) :
 * c'est ce qui empêche la section de se remplir de faux avis.
 */
class Avis extends Model
{
    protected $table = 'avis';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected function casts(): array
    {
        return ['cree_le' => 'datetime', 'note' => 'integer'];
    }

    public function produit(): BelongsTo  { return $this->belongsTo(Produit::class); }
    public function boutique(): BelongsTo { return $this->belongsTo(Boutique::class); }

    /** « Aminata Sawadogo » → « Aminata S. » : on n'affiche jamais le nom complet. */
    public static function auteurAffiche(string $nom): string
    {
        $parts = preg_split('/\s+/', trim($nom)) ?: [];
        $prenom = $parts[0] ?? 'Client';
        $initiale = isset($parts[1]) ? ' '.mb_strtoupper(mb_substr($parts[1], 0, 1)).'.' : '';

        return mb_substr($prenom.$initiale, 0, 80);
    }
}
