<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_item_id',
        'item_name',
        'change_qty',
        'balance_after',
        'reason',
        'reference',
        'performed_by',
    ];

    protected $casts = [
        'change_qty'    => 'float',
        'balance_after' => 'float',
    ];

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }
}