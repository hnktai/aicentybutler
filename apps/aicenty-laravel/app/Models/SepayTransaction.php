<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SepayTransaction extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount_in'  => 'decimal:2',
        'amount_out' => 'decimal:2',
        'payload'    => 'array',
    ];
}
