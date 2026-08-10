<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Parametre extends Model
{
    protected $table = 'parametres';
    protected $guarded = ['id'];
    public $timestamps = false;
}
