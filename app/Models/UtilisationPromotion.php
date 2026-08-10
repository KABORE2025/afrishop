<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UtilisationPromotion extends Model
{
    protected $table = 'utilisations_promotion';
    protected $guarded = ['id'];
    public $timestamps = false;
}
