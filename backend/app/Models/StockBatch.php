<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockBatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['expiry_date' => 'date'];
    }
}