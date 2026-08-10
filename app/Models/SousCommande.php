<?php

namespace App\Models;

use App\Enums\EtatFonds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La part d'une commande qui revient à UNE boutique.
 * L'objet le plus important du système : c'est ce qui est préparé,
 * expédié, livré, contesté, retourné, remboursé et payé.
 */
class SousCommande extends Model
{
    protected $table = 'sous_commandes';
    public const CREATED_AT = 'cree_le';
    public const UPDATED_AT = 'modifie_le';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'etat_fonds'             => EtatFonds::class,
            'expedie_le'             => 'datetime',
            'livre_le'               => 'datetime',
            'confirme_par_client_le' => 'datetime',
            'taux_commission_pct'    => 'float',
            'taux_retenue_source_pct'=> 'float',
        ];
    }

    public function commande(): BelongsTo { return $this->belongsTo(Commande::class); }
    public function boutique(): BelongsTo { return $this->belongsTo(Boutique::class); }
    public function lignes(): HasMany     { return $this->hasMany(LigneCommande::class); }
    public function litiges(): HasMany    { return $this->hasMany(Litige::class); }
    public function retours(): HasMany    { return $this->hasMany(Retour::class); }
    public function expedition()          { return $this->hasOne(Expedition::class); }
    public function evenements(): HasMany { return $this->hasMany(EvenementCommande::class); }

    public function aUnLitigeOuvert(): bool
    {
        return $this->litiges()->whereIn('statut', ['ouvert', 'en_examen'])->exists();
    }

    /**
     * Un retour en cours gèle aussi les fonds. Rembourser après avoir
     * payé le vendeur oblige à lui reprendre l'argent — toujours une
     * mauvaise conversation, et souvent une boutique perdue.
     */
    public function aUnRetourEnCours(): bool
    {
        return $this->retours()->whereIn('statut', ['demande', 'accepte', 'en_transit', 'recu'])->exists();
    }

    /** Écrit au journal d'audit. À appeler à CHAQUE changement d'état. */
    public function journaliser(string $type, array $donnees = [], ?int $auteurId = null, string $auteurType = 'systeme'): void
    {
        $this->evenements()->create([
            'type' => $type, 'donnees' => $donnees,
            'auteur_id' => $auteurId, 'auteur_type' => $auteurType,
        ]);
    }
}
