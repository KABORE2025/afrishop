<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentBoutique extends Model
{
    protected $table = 'documents_boutique';
    protected $guarded = ['id'];
    public $timestamps = false;
}
