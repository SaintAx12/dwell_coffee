<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\MenuItemRecipe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MenuController extends Controller
{
    public function index()
    {
        return response()->json(
            MenuItem::with('recipes.stockItem')
                ->orderBy('category')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'                    => 'required|string|max:255',
            'category'                => 'nullable|string|max:100',
            'price'                   => 'required|numeric|min:0',
            'is_available'            => 'nullable|boolean',
            'recipes'                 => 'nullable|array',
            'recipes.*.stock_item_id' => 'required|exists:stock_items,id',
            'recipes.*.quantity_used' => 'required|numeric|min:0.01',
        ]);

        return DB::transaction(function () use ($data) {
            $payload = [
                'name'     => $data['name'],
                'category' => $data['category'] ?? 'Coffee',
                'price'    => $data['price'],
            ];

            if (Schema::hasColumn('menu_items', 'is_available')) {
                $payload['is_available'] = $data['is_available'] ?? true;
            } elseif (Schema::hasColumn('menu_items', 'active')) {
                $payload['active'] = $data['is_available'] ?? true;
            }

            $item = MenuItem::create($payload);

            if (!empty($data['recipes'])) {
                $this->saveRecipes($item, $data['recipes']);
            }

            return response()->json($item->load('recipes.stockItem'), 201);
        });
    }

    public function update(Request $request, MenuItem $menuItem)
    {
        $data = $request->validate([
            'name'                    => 'required|string|max:255',
            'category'                => 'nullable|string|max:100',
            'price'                   => 'required|numeric|min:0',
            'is_available'            => 'nullable|boolean',
            'recipes'                 => 'nullable|array',
            'recipes.*.stock_item_id' => 'required|exists:stock_items,id',
            'recipes.*.quantity_used' => 'required|numeric|min:0.01',
        ]);

        return DB::transaction(function () use ($menuItem, $data) {
            $updates = [
                'name'     => $data['name'],
                'category' => $data['category'] ?? $menuItem->category,
                'price'    => $data['price'],
            ];

            if (array_key_exists('is_available', $data)) {
                if (Schema::hasColumn('menu_items', 'is_available')) {
                    $updates['is_available'] = (bool) $data['is_available'];
                } elseif (Schema::hasColumn('menu_items', 'active')) {
                    $updates['active'] = (bool) $data['is_available'];
                }
            }

            $menuItem->update($updates);

            if (array_key_exists('recipes', $data)) {
                $this->saveRecipes($menuItem, $data['recipes'] ?? []);
            }

            return response()->json($menuItem->fresh()->load('recipes.stockItem'));
        });
    }

    public function syncRecipe(Request $request, MenuItem $menuItem)
    {
        $data = $request->validate([
            'recipes'                 => 'present|array',
            'recipes.*.stock_item_id' => 'required|exists:stock_items,id',
            'recipes.*.quantity_used' => 'required|numeric|min:0.01',
        ]);

        DB::transaction(function () use ($menuItem, $data) {
            $this->saveRecipes($menuItem, $data['recipes']);
        });

        return response()->json($menuItem->fresh()->load('recipes.stockItem'));
    }

    public function toggle(MenuItem $menuItem)
    {
        if (Schema::hasColumn('menu_items', 'is_available')) {
            $menuItem->is_available = !$menuItem->is_available;
            $menuItem->save();
        } elseif (Schema::hasColumn('menu_items', 'active')) {
            $menuItem->active = !$menuItem->active;
            $menuItem->save();
        }

        return response()->json($menuItem->fresh()->load('recipes.stockItem'));
    }

    private function saveRecipes(MenuItem $menuItem, array $recipes): void
    {
        MenuItemRecipe::where('menu_item_id', $menuItem->id)->delete();

        $merged = [];
        foreach ($recipes as $row) {
            $sid = (int) $row['stock_item_id'];
            $qty = (float) $row['quantity_used'];
            if ($qty > 0) {
                $merged[$sid] = round(($merged[$sid] ?? 0) + $qty, 2);
            }
        }

        foreach ($merged as $stockItemId => $qtyUsed) {
            MenuItemRecipe::create([
                'menu_item_id'  => $menuItem->id,
                'stock_item_id' => $stockItemId,
                'quantity_used' => $qtyUsed,
            ]);
        }
    }
}