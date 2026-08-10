<?php

namespace App\Models;

use App\Enums\StatutLotQr;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un lot d'étiquettes QR = UNE FABRICATION.
 *
 * Exemple concret : le 2 août 2026, la boutique « Karité du Sahel »
 * produit 275 pots de beurre de karité qui expirent le 2 août 2028. On
 * crée un lot, on génère 275 étiquettes numérotées 0026 à 0300, on les
 * imprime et on les colle sur les pots.
 *
 * Toutes les étiquettes d'un lot partagent les mêmes dates et le même
 * fabricant. Ce qui les distingue, c'est leur numéro et leur jeton.
 */
class LotQr extends Model
{
    protected $table = 'lots_qr';
    public const CREATED_AT = 'cree_le';
    public const UPDATED_AT = 'modifie_le';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date_fabrication' => 'date',
            'date_expiration'  => 'date',
            'statut'           => StatutLotQr::class,
            'numero_debut'     => 'integer',
            'quantite'         => 'integer',
            'largeur_numero'   => 'integer',
        ];
    }

    public function produit(): BelongsTo  { return $this->belongsTo(Produit::class); }
    public function boutique(): BelongsTo { return $this->belongsTo(Boutique::class); }
    public function codes(): HasMany      { return $this->hasMany(CodeQr::class, 'lot_qr_id'); }

    /** Dernier numéro du lot. numero_debut=26, quantite=275 → 300. */
    public function numeroFin(): int
    {
        return $this->numero_debut + $this->quantite - 1;
    }

    /** Le lot est-il périmé ? Le scan doit le dire au client, pas le cacher. */
    public function estPerime(): bool
    {
        return $this->date_expiration->isPast();
    }

    /** Jours restants avant péremption (négatif si déjà périmé). */
    public function joursAvantExpiration(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->date_expiration, absolute: false);
    }

    /**
     * Propose le numéro de départ du prochain lot de ce produit.
     *
     * Reprend la numérotation là où le lot précédent s'est arrêté, pour
     * que le vendeur n'ait pas à s'en souvenir. S'il n'y a pas encore de
     * lot, on démarre à 1.
     */
    public static function prochainNumeroDebut(int $produitId): int
    {
        $dernier = static::where('produit_id', $produitId)
            ->orderByDesc('id')
            ->first();

        return $dernier ? $dernier->numeroFin() + 1 : 1;
    }

    /** Référence lisible du lot : LOT-2026-0004. */
    public static function prochaineReference(): string
    {
        $annee = now()->year;
        $dernier = static::where('reference', 'like', "LOT-$annee-%")->max('reference');
        $numero = $dernier ? ((int) substr($dernier, -4)) + 1 : 1;

        return sprintf('LOT-%d-%04d', $annee, $numero);
    }
}
