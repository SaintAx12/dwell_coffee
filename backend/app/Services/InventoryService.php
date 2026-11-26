<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\StockBatch;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    /**
     * Validate and deduct all recipe ingredients for an order atomically.
     * Must be called inside an active DB::transaction().
     */
    public function deductForOrder(array $orderItems, string $reference, string $performedBy): void
    {
        $menuIds = collect($orderItems)
            ->map(fn ($line) => $line['menu_item_id'] ?? $line['id'] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($menuIds->isEmpty()) {
            return;
        }

        $menuItems = MenuItem::with('recipes.stockItem')
            ->whereIn('id', $menuIds)
            ->get()
            ->keyBy('id');

        // 1. Aggregate total ingredient quantities needed across the entire order
        $requiredByStockId = [];

        foreach ($orderItems as $line) {
            $menuId = (int) ($line['menu_item_id'] ?? $line['id'] ?? 0);
            $orderQty = (float) ($line['quantity'] ?? $line['qty'] ?? 0);

            if ($orderQty <= 0 || !$menuItems->has($menuId)) {
                continue;
            }

            $menuItem = $menuItems->get($menuId);

            foreach ($menuItem->recipes as $recipe) {
                $stockId = (int) $recipe->stock_item_id;
                $needed = round((float) $recipe->quantity_used * $orderQty, 2);

                if ($needed <= 0) {
                    continue;
                }

                $requiredByStockId[$stockId] = round(($requiredByStockId[$stockId] ?? 0) + $needed, 2);
            }
        }

        if (empty($requiredByStockId)) {
            return;
        }

        // 2. Lock all affected stock rows so concurrent orders never oversell
        $stockItems = StockItem::whereIn('id', array_keys($requiredByStockId))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        // 3. Block the order if ANY ingredient is short
        $shortages = [];
        foreach ($requiredByStockId as $stockId => $needed) {
            $stock = $stockItems->get($stockId);
            $available = (float) ($stock?->quantity ?? 0);

            if (!$stock || $available + 0.0001 < $needed) {
                $name = $stock?->name ?? "Ingredient #{$stockId}";
                $unit = $stock?->unit ?? '';
                $shortages[] = "{$name} (need {$needed} {$unit}, only {$available} {$unit} in stock)";
            }
        }

        if (!empty($shortages)) {
            throw ValidationException::withMessages([
                'stock' => ['Order blocked — insufficient stock for: ' . implode('; ', $shortages)],
            ]);
        }

        // 4. Deduct quantities from stock_items + oldest FIFO batches, and log each movement
        foreach ($requiredByStockId as $stockId => $needed) {
            $stock = $stockItems->get($stockId);
            $newBalance = max(0, round((float) $stock->quantity - $needed, 2));
            $stock->quantity = $newBalance;

            if (Schema::hasTable('stock_batches')) {
                $toDeduct = $needed;
                $batches = StockBatch::where('stock_item_id', $stock->id)
                    ->where('remaining_qty', '>', 0)
                    ->orderByRaw('expiry_date IS NULL ASC')
                    ->orderBy('expiry_date', 'asc')
                    ->orderBy('id', 'asc')
                    ->get();

                foreach ($batches as $batch) {
                    if ($toDeduct <= 0) {
                        break;
                    }
                    $take = min($toDeduct, (float) $batch->remaining_qty);
                    $batch->remaining_qty = max(0, round((float) $batch->remaining_qty - $take, 2));
                    $batch->save();
                    $toDeduct = round($toDeduct - $take, 2);
                }

                $nextExpiry = StockBatch::where('stock_item_id', $stock->id)
                    ->where('remaining_qty', '>', 0)
                    ->whereNotNull('expiry_date')
                    ->orderBy('expiry_date', 'asc')
                    ->value('expiry_date');

                $stock->expiry_date = $newBalance > 0 ? $nextExpiry : null;
            } elseif ($newBalance <= 0) {
                $stock->expiry_date = null;
            }

            $stock->save();

            $this->recordMovement(
                $stock,
                -$needed,
                $newBalance,
                'order_sale',
                $reference,
                $performedBy
            );
        }
    }

    /**
     * Restore recipe ingredients back to stock when an order is voided/cancelled/refunded.
     * Must be called inside an active DB::transaction().
     */
    public function restoreForOrder(array $orderItems, string $reference, string $performedBy): void
    {
        $menuIds = collect($orderItems)
            ->map(fn ($line) => $line['menu_item_id'] ?? $line['id'] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($menuIds->isEmpty()) {
            return;
        }

        $menuItems = MenuItem::with('recipes.stockItem')
            ->whereIn('id', $menuIds)
            ->get()
            ->keyBy('id');

        $restoreByStockId = [];

        foreach ($orderItems as $line) {
            $menuId = (int) ($line['menu_item_id'] ?? $line['id'] ?? 0);
            $orderQty = (float) ($line['quantity'] ?? $line['qty'] ?? 0);

            if ($orderQty <= 0 || !$menuItems->has($menuId)) {
                continue;
            }

            foreach ($menuItems->get($menuId)->recipes as $recipe) {
                $stockId = (int) $recipe->stock_item_id;
                $restoreQty = round((float) $recipe->quantity_used * $orderQty, 2);

                if ($restoreQty > 0) {
                    $restoreByStockId[$stockId] = round(($restoreByStockId[$stockId] ?? 0) + $restoreQty, 2);
                }
            }
        }

        if (empty($restoreByStockId)) {
            return;
        }

        $stockItems = StockItem::whereIn('id', array_keys($restoreByStockId))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($restoreByStockId as $stockId => $restoreQty) {
            $stock = $stockItems->get($stockId);
            if (!$stock) {
                continue;
            }

            $newBalance = round((float) $stock->quantity + $restoreQty, 2);
            $stock->quantity = $newBalance;
            $stock->save();

            if (Schema::hasTable('stock_batches')) {
                $latestBatch = StockBatch::where('stock_item_id', $stock->id)
                    ->orderBy('id', 'desc')
                    ->first();

                if ($latestBatch) {
                    $latestBatch->remaining_qty = round((float) $latestBatch->remaining_qty + $restoreQty, 2);
                    $latestBatch->save();
                } else {
                    StockBatch::create([
                        'stock_item_id' => $stock->id,
                        'quantity'      => $restoreQty,
                        'remaining_qty' => $restoreQty,
                        'unit_cost'     => $stock->unit_cost ?? 0,
                        'expiry_date'   => $stock->expiry_date,
                        'received_by'   => $performedBy,
                    ]);
                }
            }

            $this->recordMovement(
                $stock,
                +$restoreQty,
                $newBalance,
                'order_void',
                $reference,
                $performedBy
            );
        }
    }

    public function recordMovement(
        StockItem $stock,
        float $changeQty,
        float $balanceAfter,
        string $reason,
        ?string $reference = null,
        ?string $performedBy = null
    ): void {
        if (!Schema::hasTable('stock_movements') || abs($changeQty) < 0.0001) {
            return;
        }

        StockMovement::create([
            'stock_item_id' => $stock->id,
            'item_name'     => $stock->name,
            'change_qty'    => round($changeQty, 2),
            'balance_after' => round($balanceAfter, 2),
            'reason'        => $reason,
            'reference'     => $reference,
            'performed_by'  => $performedBy ?? 'System',
        ]);
    }
}