<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TauxChange extends Model
{
    protected $table = 'taux_change';
    protected $guarded = ['id'];
    public $timestamps = false;
}
