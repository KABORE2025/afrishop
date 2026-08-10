<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Traduction extends Model
{
    protected $table = 'traductions';
    protected $guarded = ['id'];
    public $timestamps = false;
}
