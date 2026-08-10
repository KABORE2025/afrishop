<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalAdministration extends Model
{
    protected $table = 'journal_administration';
    protected $guarded = ['id'];
    public $timestamps = false;
}
