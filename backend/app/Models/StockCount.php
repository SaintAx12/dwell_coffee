<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCount extends Model
{
    protected $guarded = [];

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }
}