<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attribut extends Model
{
    protected $table = 'attributs';
    protected $guarded = ['id'];
    public $timestamps = false;
}
