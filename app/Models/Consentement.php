<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Consentement extends Model
{
    protected $table = 'consentements';
    protected $guarded = ['id'];
    public $timestamps = false;
}
