<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScanQr extends Model
{
    protected $table = 'scans_qr';
    protected $guarded = ['id'];
    public $timestamps = false;
}
