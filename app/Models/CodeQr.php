<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une étiquette physique unique.
 *
 * Deux identifiants, deux usages qu'il ne faut jamais confondre :
 *
 *   code_lisible (02/08/2026/0026)
 *     → IDENTIFIE. Imprimé en clair, sert à l'inventaire, aux
 *       réclamations téléphoniques, à la saisie manuelle. Prévisible.
 *
 *   jeton (K7M2P9QRXW4T)
 *     → AUTHENTIFIE. Encodé dans le QR, tiré au hasard, impossible à
 *       deviner. C'est lui, et lui seul, qui prouve l'origine.
 *
 * Confondre les deux, c'est-à-dire mettre le code lisible dans l'URL du
 * QR, réduirait à néant toute la protection anti-contrefaçon.
 */
class CodeQr extends Model
{
    protected $table = 'codes_qr';
    public const CREATED_AT = 'cree_le';
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'numero'          => 'integer',
            'nb_scans'        => 'integer',
            'premier_scan_le' => 'datetime',
            'dernier_scan_le' => 'datetime',
        ];
    }

    public function lot(): BelongsTo   { return $this->belongsTo(LotQr::class, 'lot_qr_id'); }
    public function scans(): HasMany   { return $this->hasMany(ScanQr::class, 'code_qr_id'); }

    /** URL complète encodée dans l'image du QR code. */
    public function urlVerification(): string
    {
        return rtrim(config('afrishop.qr.url_verification'), '/') . '/' . $this->jeton;
    }

    /**
     * Ce code présente-t-il un profil suspect ?
     *
     * Une étiquette légitime est scannée quelques fois : par le client
     * qui achète, éventuellement par un proche. Des dizaines de scans
     * depuis des lieux différents signifient presque toujours que
     * l'étiquette a été photocopiée et collée sur plusieurs produits.
     *
     * On ne bloque pas automatiquement — un produit exposé sur un stand
     * de marché peut être scanné par beaucoup de curieux. On signale,
     * et un humain tranche.
     */
    public function estSuspect(): bool
    {
        return $this->nb_scans >= config('afrishop.qr.seuil_scans_suspects', 50);
    }

    /** Nombre de lieux distincts d'où ce code a été scanné. */
    public function nbVillesDistinctes(): int
    {
        return $this->scans()->whereNotNull('ville_estimee')->distinct('ville_estimee')->count('ville_estimee');
    }
}
