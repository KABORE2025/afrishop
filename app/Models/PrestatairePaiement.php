<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrestatairePaiement extends Model
{
    protected $table = 'prestataires_paiement';
    protected $guarded = ['id'];
    public $timestamps = false;
}
