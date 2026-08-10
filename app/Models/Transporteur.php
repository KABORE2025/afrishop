<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transporteur extends Model
{
    protected $table = 'transporteurs';
    protected $guarded = ['id'];
    public $timestamps = false;
}
