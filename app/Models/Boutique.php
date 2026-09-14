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

    public function agentsRemise(): HasMany
    {
        return $this->hasMany(AgentRemiseBoutique::class);
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

    /**
     * La version SQL de `peutVendre()`, pour filtrer une liste.
     *
     * POURQUOI ELLE EXISTE. `peutVendre()` s'évalue en PHP, sur une
     * boutique déjà chargée : parfait pour vérifier une commande,
     * inutilisable pour construire un catalogue. Sans équivalent SQL,
     * la vitrine affichait des produits de boutiques incapables de
     * vendre, et le client ne l'apprenait qu'à la dernière étape du
     * tunnel — après avoir saisi son nom, son téléphone et son adresse.
     *
     * LES DEUX DÉFINITIONS DOIVENT RESTER IDENTIQUES. Toute condition
     * ajoutée à `peutVendre()` doit l'être ici le même jour, sinon le
     * catalogue et le paiement se remettent à diverger — exactement le
     * défaut que ce scope corrige.
     */
    public function scopePeutVendre(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where('statut', 'actif')
            ->whereNotNull('paiement_numero')
            ->whereNotNull('paiement_verifie_le')
            // Non fermée : soit aucune fermeture programmée, soit la
            // période de fermeture ne couvre pas aujourd'hui.
            ->where(fn ($q) => $q
                ->whereNull('fermee_du')
                ->orWhere('fermee_du', '>', now()->toDateString())
                ->orWhere(fn ($q) => $q
                    ->whereNotNull('fermee_au')
                    ->where('fermee_au', '<', now()->toDateString())));
    }

    /**
     * Pourquoi cette boutique ne peut pas vendre, en français.
     *
     * Renvoie `null` si elle le peut. Le message est destiné au CLIENT,
     * pas à l'exploitant : il dit ce qui se passe et quand revenir,
     * sans exposer que le compte de reversement du vendeur n'est pas
     * vérifié — ce qui ne regarde pas l'acheteur et ferait douter de la
     * boutique.
     */
    public function raisonIndisponibilite(): ?string
    {
        if ($this->statut !== 'actif') {
            return "La boutique « {$this->nom} » ne vend pas sur Afrishop actuellement.";
        }

        if ($this->estFermee()) {
            return $this->fermee_au
                ? "La boutique « {$this->nom} » est fermée jusqu'au "
                    . $this->fermee_au->format('d/m/Y') . '.'
                : "La boutique « {$this->nom} » est temporairement fermée.";
        }

        /*
         * Compte de reversement non vérifié. C'est la garantie que
         * l'argent encaissé pourra être rendu au vendeur : encaisser
         * sans elle, c'est retenir une somme sans savoir à quel numéro
         * la reverser. Le client n'a pas à connaître ce détail.
         */
        if ($this->paiement_numero === null || $this->paiement_verifie_le === null) {
            return "La boutique « {$this->nom} » n'accepte pas encore les commandes en ligne.";
        }

        return null;
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
