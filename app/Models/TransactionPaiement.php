<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionPaiement extends Model
{
    protected $table = 'transactions_paiement';
    protected $guarded = ['id'];
    public $timestamps = false;
}
