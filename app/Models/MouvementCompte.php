<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MouvementCompte extends Model
{
    protected $table = 'mouvements_compte';
    protected $guarded = ['id'];
    public $timestamps = false;
}
