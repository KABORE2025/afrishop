<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TauxTaxe extends Model
{
    protected $table = 'taux_taxe';
    protected $guarded = ['id'];
    public $timestamps = false;
}
