<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reversement extends Model
{
    protected $table = 'reversements';
    protected $guarded = ['id'];
    public $timestamps = false;
}
