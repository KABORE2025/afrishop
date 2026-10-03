<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanQr extends Model
{
    protected $table = 'scans_qr';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected function casts(): array
    {
        return ['scanne_le' => 'datetime'];
    }

    public function code(): BelongsTo { return $this->belongsTo(CodeQr::class, 'code_qr_id'); }

    /**
     * Empreinte d'une adresse IP ou d'un navigateur. LotQrService l'appelait
     * sans qu'elle existe : le tout premier scan d'un client plantait.
     *
     * On garde une empreinte, jamais la valeur : elle suffit à repérer
     * qu'une même étiquette est scannée depuis beaucoup d'endroits (signe
     * de copie), sans constituer un fichier des IP des clients. Salée avec
     * la clé de l'application, pour qu'on ne puisse pas la retrouver en
     * essayant toutes les adresses possibles.
     */
    public static function hacher(?string $valeur): ?string
    {
        return $valeur === null || $valeur === ''
            ? null
            : hash_hmac('sha256', $valeur, (string) config('app.key'));
    }
}
