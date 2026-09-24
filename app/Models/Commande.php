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
    public function transactions(): HasMany  { return $this->hasMany(TransactionPaiement::class); }

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
     * Référence lisible, préfixée par le pays : BF-CMD-2026-7KQ4XM.
     * Le préfixe évite toute collision entre pays et permet de savoir
     * d'un coup d'œil où une commande a été passée — utile au support,
     * qui travaille au téléphone.
     *
     * LA PARTIE FINALE EST ALÉATOIRE, plus un compteur. Avec un compteur
     * (000041, 000042…), n'importe qui devinait les références voisines
     * de la sienne — et la page /commande/{reference} ne demande QUE la
     * référence. Deux commandes simultanées pouvaient aussi calculer le
     * même numéro. Six caractères parmi 31 donnent près de 900 millions
     * de combinaisons ; les lettres ambiguës au téléphone (0/O, 1/I/L)
     * sont exclues.
     */
    public static function prochaineReference(Pays $pays): string
    {
        $prefixe = "{$pays->code_iso2}-CMD-" . now()->year . '-';
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

        do {
            $suffixe = '';
            for ($i = 0; $i < 6; $i++) {
                $suffixe .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $reference = $prefixe . $suffixe;
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    public function pays(): BelongsTo { return $this->belongsTo(Pays::class); }
}
