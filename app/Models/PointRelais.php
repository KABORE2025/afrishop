<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PointRelais extends Model
{
    protected $table = 'points_relais';
    protected $guarded = ['id'];
    public $timestamps = false;
}
