<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZoneLivraison extends Model
{
    protected $table = 'zones_livraison';
    protected $guarded = ['id'];
    public $timestamps = false;
}
