<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Litige extends Model
{
    protected $table = 'litiges';
    protected $guarded = ['id'];
    public $timestamps = false;
}
