<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperateurPaiement extends Model
{
    protected $table = 'operateurs_paiement';
    protected $guarded = ['id'];
    public $timestamps = false;
}
