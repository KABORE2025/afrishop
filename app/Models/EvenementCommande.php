<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvenementCommande extends Model
{
    protected $table = 'evenements_commande';
    protected $guarded = ['id'];
    public $timestamps = false;
}
