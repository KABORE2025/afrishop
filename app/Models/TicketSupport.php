<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketSupport extends Model
{
    protected $table = 'tickets_support';
    protected $guarded = ['id'];
    public $timestamps = false;
}
