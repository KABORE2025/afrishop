<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GabaritNotification extends Model
{
    protected $table = 'gabarits_notification';
    protected $guarded = ['id'];
    public $timestamps = false;
}
