<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'price'        => 'float',
        'is_available' => 'boolean',
    ];

    protected $appends = [
        'is_in_stock',
        'max_servings',
        'missing_ingredients',
    ];

    public function recipes()
    {
        return $this->hasMany(MenuItemRecipe::class);
    }

    public function stockItems()
    {
        return $this->belongsToMany(StockItem::class, 'menu_item_recipes')
            ->withPivot('quantity_used')
            ->withTimestamps();
    }

    public function getMaxServingsAttribute(): ?int
    {
        $recipes = $this->relationLoaded('recipes')
            ? $this->recipes
            : $this->recipes()->with('stockItem')->get();

        if ($recipes->isEmpty()) {
            return null; // No recipe linked = unlimited / not stock-constrained
        }

        $minServings = PHP_INT_MAX;

        foreach ($recipes as $recipe) {
            $needed = (float) $recipe->quantity_used;
            if ($needed <= 0) {
                continue;
            }

            $available = (float) ($recipe->stockItem?->quantity ?? 0);
            $possible = (int) floor($available / $needed);

            if ($possible < $minServings) {
                $minServings = $possible;
            }
        }

        return $minServings === PHP_INT_MAX ? null : max(0, $minServings);
    }

    public function getIsInStockAttribute(): bool
    {
        $max = $this->max_servings;
        return $max === null || $max > 0;
    }

    public function getMissingIngredientsAttribute(): array
    {
        $recipes = $this->relationLoaded('recipes')
            ? $this->recipes
            : $this->recipes()->with('stockItem')->get();

        $missing = [];

        foreach ($recipes as $recipe) {
            $needed = (float) $recipe->quantity_used;
            $available = (float) ($recipe->stockItem?->quantity ?? 0);
            $name = $recipe->stockItem?->name ?? 'Ingredient';
            $unit = $recipe->stockItem?->unit ?? '';

            if ($available < $needed) {
                $missing[] = "{$name} (need {$needed}{$unit}, {$available}{$unit} left)";
            }
        }

        return $missing;
    }
}