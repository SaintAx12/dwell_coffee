<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MenuItemRecipe extends Model
{
    use HasFactory;

    protected $fillable = [
        'menu_item_id',
        'stock_item_id',
        'quantity_used',
    ];

    protected $casts = [
        'quantity_used' => 'float',
    ];

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }
}