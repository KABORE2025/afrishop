<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ce que le client paie : un panier, un paiement, un numéro de suivi.
 * Le travail réel se passe dans les sous-commandes.
 */
class Commande extends Model
{
    protected $table = 'commandes';
    public const CREATED_AT = 'cree_le';
    public const UPDATED_AT = 'modifie_le';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['paye_le' => 'datetime'];
    }

    public function sousCommandes(): HasMany { return $this->hasMany(SousCommande::class); }

    /**
     * Recalcule le statut global à partir des sous-commandes.
     *
     * Le statut d'une commande n'est jamais saisi : il se DÉDUIT de
     * l'état de ses colis. Toute autre approche finit par produire des
     * incohérences (commande « terminée » avec un colis non expédié).
     */
    public function rafraichirStatut(): void
    {
        $statuts = $this->sousCommandes()->pluck('statut');

        if ($statuts->isEmpty()) {
            return;
        }

        $this->statut = match (true) {
            $statuts->every(fn ($s) => $s === 'annulee')                                => 'annulee',
            $statuts->every(fn ($s) => in_array($s, ['livree', 'retournee', 'annulee'])) => 'livree',
            $statuts->contains('livree')                                                => 'partiellement_livree',
            default                                                                      => 'en_preparation',
        };

        $this->save();
    }

    /**
     * Référence lisible, préfixée par le pays : BF-CMD-2026-000042.
     * Le préfixe évite toute collision entre pays et permet de savoir
     * d'un coup d'œil où une commande a été passée — utile au support,
     * qui travaille au téléphone.
     */
    public static function prochaineReference(Pays $pays): string
    {
        $annee = now()->year;
        $prefixe = "{$pays->code_iso2}-CMD-{$annee}-";
        $dernier = static::where('reference', 'like', $prefixe . '%')->max('reference');
        $numero = $dernier ? ((int) substr($dernier, -6)) + 1 : 1;

        return sprintf('%s%06d', $prefixe, $numero);
    }

    public function pays(): BelongsTo { return $this->belongsTo(Pays::class); }
}
