<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RetenueSource extends Model
{
    protected $table = 'retenues_source';
    protected $guarded = ['id'];
    public $timestamps = false;
}
