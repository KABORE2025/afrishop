<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EncaissementEspeces extends Model
{
    protected $table = 'encaissements_especes';
    protected $guarded = ['id'];
    public $timestamps = false;
}
