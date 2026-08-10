<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ce qu'on met réellement dans le panier.
 *
 * Un produit sans déclinaison possède une variante « Standard » : cela
 * évite d'avoir deux chemins de code, un pour les produits simples et
 * un pour les autres — source classique d'erreurs de stock.
 */
class VarianteProduit extends Model
{
    protected $table = 'variantes_produit';
    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['stock' => 'integer', 'actif' => 'boolean', 'defaut' => 'boolean'];
    }

    public function produit(): BelongsTo { return $this->belongsTo(Produit::class); }

    /** Prix effectif : celui de la variante, sinon celui du produit. */
    public function prixTtc(): int
    {
        return (int) ($this->prix_ttc_cfa ?? $this->produit->prix_ttc_cfa);
    }

    public function enRupture(): bool     { return $this->stock <= 0; }
    public function stockCritique(): bool { return $this->stock > 0 && $this->stock <= $this->seuil_alerte; }
}
