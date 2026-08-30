<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un mouvement d'argent tenté auprès du prestataire de paiement.
 *
 * `reference_externe` est la SEULE clé fiable pour rapprocher une
 * notification ou une vérification : c'est l'identifiant que le
 * prestataire connaît. Une commande peut porter plusieurs transactions
 * — une par tentative — et seule la dernière réussie compte.
 *
 * `$timestamps = false` est voulu : la table a ses propres colonnes de
 * date, `initiee_le` et `finalisee_le`, et l'écart entre les deux est
 * ce qu'on regarde quand un paiement traîne.
 */
class TransactionPaiement extends Model
{
    protected $table = 'transactions_paiement';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'montant_cfa'   => 'integer',
            'frais_psp_cfa' => 'integer',
            'initiee_le'    => 'datetime',
            'finalisee_le'  => 'datetime',
        ];
    }

    /**
     * La commande payée.
     *
     * Ajoutée pour `afrishop:verifier-paiements --reference=…`, qui
     * doit pouvoir retrouver une transaction à partir de la référence
     * lisible par un humain — la seule que le client connaisse.
     */
    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }
}
