<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageTicket extends Model
{
    protected $table = 'messages_ticket';
    protected $guarded = ['id'];
    public $timestamps = false;
}
