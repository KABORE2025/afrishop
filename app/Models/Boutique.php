<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Une boutique = un vendeur indépendant.
 *
 * Règle centrale : une boutique n'est PAS une catégorie. Plusieurs
 * boutiques peuvent vendre dans la même catégorie (deux boutiques de
 * beauté qui se font concurrence), et une boutique peut vendre dans
 * plusieurs catégories. La « catégorie principale » n'est qu'une
 * étiquette d'annuaire.
 */
class Boutique extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'boutiques';
    public const CREATED_AT = 'cree_le';
    public const UPDATED_AT = 'modifie_le';
    public const DELETED_AT = 'supprime_le';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'taux_commission'     => 'float',
            'assujetti_tva'       => 'boolean',
            'valide_le'           => 'datetime',
            'paiement_verifie_le' => 'datetime',
            'fermee_du'           => 'date',
            'fermee_au'           => 'date',
        ];
    }

    // ---------------------------------------------------------------
    // Relations
    // ---------------------------------------------------------------

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class);
    }

    public function pays(): BelongsTo
    {
        return $this->belongsTo(Pays::class);
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }

    public function operateurPaiement(): BelongsTo
    {
        return $this->belongsTo(OperateurPaiement::class, 'operateur_paiement_id');
    }

    public function mouvements(): HasMany
    {
        return $this->hasMany(MouvementCompte::class);
    }

    public function produits(): HasMany
    {
        return $this->hasMany(Produit::class);
    }

    public function sousCommandes(): HasMany
    {
        return $this->hasMany(SousCommande::class);
    }

    public function reversements(): HasMany
    {
        return $this->hasMany(Reversement::class);
    }

    // ---------------------------------------------------------------
    // Règles métier
    // ---------------------------------------------------------------

    /**
     * Une boutique peut-elle recevoir une commande maintenant ?
     *
     * Trois conditions, et les deux dernières sont régulièrement
     * oubliées à la conception :
     *
     *  1. elle est active ;
     *  2. son compte de reversement est VÉRIFIÉ — sinon on encaisse
     *     un argent qu'on ne saura pas lui rendre ;
     *  3. elle n'est pas en congés — sinon la commande tombe chez un
     *     vendeur absent, et c'est le client qui attend.
     */
    public function peutVendre(): bool
    {
        return $this->statut === 'actif'
            && $this->paiement_numero !== null
            && $this->paiement_verifie_le !== null
            && ! $this->estFermee();
    }

    /** Congés, deuil, rupture générale. */
    public function estFermee(): bool
    {
        if ($this->fermee_du === null) {
            return false;
        }

        $aujourdhui = now()->toDateString();

        return $aujourdhui >= $this->fermee_du
            && ($this->fermee_au === null || $aujourdhui <= $this->fermee_au);
    }

    /**
     * Est-elle immatriculée ? La question vaut de l'argent : au Burkina,
     * l'absence de numéro fiscal coûte 25 % de retenue à la source au
     * lieu de 5 %.
     */
    public function estImmatriculee(): bool
    {
        return $this->numero_fiscal !== null && $this->regime_fiscal !== 'non_immatricule';
    }

    /**
     * Réputation calculée à partir de l'historique réel, jamais déclarée.
     *
     * Sert à départager deux boutiques qui vendent le même produit. En
     * dessous du seuil de commandes traitées, on renvoie null plutôt
     * qu'une note : une note sur deux ventes ne veut rien dire et
     * pénaliserait injustement une boutique qui démarre.
     *
     * @return array{note: float|null, livrees: int, litiges: int, delai_moyen: float|null}
     */
    public function reputation(): array
    {
        $sous = $this->sousCommandes()
            ->whereIn('statut', ['livree', 'annulee'])
            ->withCount(['litiges'])
            ->get();

        $livrees  = $sous->where('statut', 'livree')->count();
        $annulees = $sous->where('statut', 'annulee')->count();
        $litiges  = $sous->sum('litiges_count');
        $traitees = $livrees + $annulees;

        // Délai moyen entre la commande et l'expédition, en jours.
        $expediees = $sous->whereNotNull('expedie_le');
        $delai = $expediees->count()
            ? $expediees->avg(fn ($s) => $s->cree_le->diffInDays($s->expedie_le, absolute: true))
            : null;

        if ($traitees < config('afrishop.reputation_seuil_commandes', 3)) {
            return ['note' => null, 'livrees' => $livrees, 'litiges' => $litiges, 'delai_moyen' => $delai];
        }

        /*
         * Formule : on part de 5 et on retire.
         *   - chaque litige coûte cher (coefficient 8) car c'est le
         *     signal le plus fort d'un problème réel
         *   - une annulation coûte moins (coefficient 3) : elle peut
         *     venir d'une rupture de stock honnête
         *   - un délai d'expédition supérieur à 3 jours retire 0,5
         */
        $note = 5
            - ($litiges / max(1, $traitees)) * 8
            - ($annulees / max(1, $traitees)) * 3
            - (($delai !== null && $delai > 3) ? 0.5 : 0);

        return [
            'note'        => round(max(1, min(5, $note)), 1),
            'livrees'     => $livrees,
            'litiges'     => $litiges,
            'delai_moyen' => $delai !== null ? round($delai, 1) : null,
        ];
    }
}
