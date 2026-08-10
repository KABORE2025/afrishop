<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LignePanier extends Model
{
    protected $table = 'lignes_panier';
    protected $guarded = ['id'];
    public $timestamps = false;
}
