<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LigneRetour extends Model
{
    protected $table = 'lignes_retour';
    protected $guarded = ['id'];
    public $timestamps = false;
}
