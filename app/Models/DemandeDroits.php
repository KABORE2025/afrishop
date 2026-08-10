<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DemandeDroits extends Model
{
    protected $table = 'demandes_droits';
    protected $guarded = ['id'];
    public $timestamps = false;
}
