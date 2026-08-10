<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expedition extends Model
{
    protected $table = 'expeditions';
    protected $guarded = ['id'];
    public $timestamps = false;
}
