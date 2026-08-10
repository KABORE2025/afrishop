<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReconciliationPsp extends Model
{
    protected $table = 'reconciliations_psp';
    protected $guarded = ['id'];
    public $timestamps = false;
}
