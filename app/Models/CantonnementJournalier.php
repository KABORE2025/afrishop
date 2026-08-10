<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CantonnementJournalier extends Model
{
    protected $table = 'cantonnement_journalier';
    protected $guarded = ['id'];
    public $timestamps = false;
}
