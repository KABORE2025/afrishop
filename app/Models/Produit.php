<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Produit extends Model
{
    use SoftDeletes;

    protected $table = 'produits';
    public const CREATED_AT = 'cree_le';
    public const UPDATED_AT = 'modifie_le';
    public const DELETED_AT = 'supprime_le';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['prix_ttc_cfa' => 'integer', 'actif' => 'boolean', 'tracable' => 'boolean'];
    }

    public function boutique(): BelongsTo  { return $this->belongsTo(Boutique::class); }
    public function categorie(): BelongsTo { return $this->belongsTo(Categorie::class); }
    public function lotsQr(): HasMany      { return $this->hasMany(LotQr::class, 'produit_id'); }
    public function variantes(): HasMany   { return $this->hasMany(VarianteProduit::class); }
    public function medias()               { return $this->morphMedias(); }

    /** Stock total, toutes variantes confondues. */
    public function stockTotal(): int
    {
        return (int) $this->variantes()->where('actif', true)->sum('stock');
    }

    /** Les photos, dans l'ordre voulu par le vendeur. */
    public function morphMedias()
    {
        return Media::where('proprietaire_type', 'produit')
            ->where('proprietaire_id', $this->id)->orderBy('ordre');
    }

    /** Uniquement ce qui est réellement achetable en vitrine. */
    /**
     * Ce qui est réellement achetable : actif, modéré et publié, chez
     * une boutique ouverte. Le filtre de modération est nouveau — une
     * plateforme qui vend de l'anti-contrefaçon ne peut pas laisser
     * publier n'importe quoi dans son propre catalogue.
     */
    public function scopeVisible(Builder $q): Builder
    {
        return $q->where('actif', true)
                 ->where('statut_moderation', 'publie')
                 ->whereHas('boutique', fn ($b) => $b->where('statut', 'actif'));
    }

    /**
     * Les offres concurrentes du MÊME produit chez d'autres boutiques.
     *
     * Le rapprochement se fait sur le nom normalisé (minuscules, sans
     * accents, sans ponctuation). C'est volontairement approximatif :
     * « Beurre de karité brut » et « beurre de karite brut » doivent se
     * retrouver, sans imposer un référentiel produit commun que des
     * artisans ne rempliraient jamais correctement.
     *
     * Si le catalogue grossit, remplacer par une table
     * « produits_references » alimentée à la validation du produit.
     */
    public function offresConcurrentes()
    {
        $clef = static::normaliserNom($this->nom);

        return static::visible()
            ->where('id', '!=', $this->id)
            ->where('stock', '>', 0)
            ->get()
            ->filter(fn ($p) => static::normaliserNom($p->nom) === $clef)
            ->sortBy('prix_ttc_cfa')
            ->values();
    }

    public static function normaliserNom(string $nom): string
    {
        $sansAccents = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nom);

        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($sansAccents));
    }

    /** Référence lisible, préfixée par le code boutique : BF-V001-P0007. */
    public static function prochaineReference(Boutique $boutique): string
    {
        $prefixe = $boutique->code . '-P';
        $dernier = static::where('reference', 'like', $prefixe . '%')->max('reference');
        $numero = $dernier ? ((int) substr($dernier, strlen($prefixe))) + 1 : 1;

        return sprintf('%s%04d', $prefixe, $numero);
    }

    /** Slug unique : on ajoute un compteur plutôt qu'un suffixe aléatoire, plus lisible en URL. */
    public static function slugUnique(string $nom): string
    {
        $base = Str::slug($nom);
        $slug = $base;
        $n = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base . '-' . ++$n;
        }

        return $slug;
    }
}
