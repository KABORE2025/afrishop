<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistreTraitement extends Model
{
    protected $table = 'registre_traitements';
    protected $guarded = ['id'];
    public $timestamps = false;
}
