<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Retour extends Model
{
    protected $table = 'retours';
    protected $guarded = ['id'];
    public $timestamps = false;
}
