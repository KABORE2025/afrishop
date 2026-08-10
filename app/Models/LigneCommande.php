<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LigneCommande extends Model
{
    protected $table = 'lignes_commande';
    protected $guarded = ['id'];
    public $timestamps = false;
}
