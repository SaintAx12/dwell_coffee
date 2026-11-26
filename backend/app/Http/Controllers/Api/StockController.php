<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockBatch;
use App\Models\StockCount;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function __construct(private InventoryService $inventory)
    {
    }

    public function index()
    {
        return StockItem::with(['batches', 'counts' => fn ($q) => $q->take(3)])
            ->orderBy('name')
            ->get()
            ->map(function ($item) {
                $item->is_low = (float) $item->quantity <= (float) $item->min_level;

                if ((float) $item->quantity <= 0) {
                    $item->is_expiring = false;
                    return $item;
                }

                $warnDays = (int) ($item->expiry_warning_days ?? 2);
                $cutoff = now()->startOfDay()->addDays($warnDays);

                $itemExpiring = $item->expiry_date
                    && Carbon::parse($item->expiry_date)->startOfDay()->lte($cutoff);

                $batchExpiring = $item->batches
                    ->where('remaining_qty', '>', 0)
                    ->contains(
                        fn ($b) => $b->expiry_date
                            && Carbon::parse($b->expiry_date)->startOfDay()->lte($cutoff)
                    );

                $item->is_expiring = $itemExpiring || $batchExpiring;
                return $item;
            });
    }

    public function movements()
    {
        return response()->json(
            StockMovement::latest()->limit(100)->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'                => 'required|string|max:255',
            'unit'                => 'required|string|max:50',
            'quantity'            => 'required|numeric|min:0',
            'min_level'           => 'required|numeric|min:0',
            'unit_cost'           => 'nullable|numeric|min:0',
            'expiry_date'         => 'nullable|date',
            'expiry_warning_days' => 'nullable|integer|min:0|max:365',
            'shelf_life_days'     => 'nullable|integer|min:1|max:365',
        ]);

        $data['expiry_warning_days'] = $data['expiry_warning_days'] ?? 2;

        if (empty($data['expiry_date']) && !empty($data['shelf_life_days'])) {
            $data['expiry_date'] = now()->addDays((int) $data['shelf_life_days'])->toDateString();
        }

        $stock = StockItem::create($data);
        $performer = $request->user()?->name ?? 'Owner';

        if ((float) $stock->quantity > 0) {
            StockBatch::create([
                'stock_item_id' => $stock->id,
                'quantity'      => $stock->quantity,
                'remaining_qty' => $stock->quantity,
                'unit_cost'     => $stock->unit_cost,
                'expiry_date'   => $stock->expiry_date,
                'received_by'   => $performer,
            ]);

            $this->inventory->recordMovement(
                $stock,
                (float) $stock->quantity,
                (float) $stock->quantity,
                'initial_stock',
                'New Stock Item',
                $performer
            );
        }

        return response()->json($stock->fresh(), 201);
    }

    public function stockIn(Request $request, int $id)
    {
        $stock = StockItem::findOrFail($id);

        $data = $request->validate([
            'quantity'    => 'required|numeric|min:0.01',
            'unit_cost'   => 'nullable|numeric|min:0',
            'expiry_date' => 'nullable|date',
        ]);

        $expiryDate = $data['expiry_date'] ?? null;
        if (!$expiryDate && !empty($stock->shelf_life_days)) {
            $expiryDate = now()->addDays((int) $stock->shelf_life_days)->toDateString();
        }

        $performer = $request->user()?->name ?? 'Owner';
        $addedQty = (float) $data['quantity'];

        $batch = StockBatch::create([
            'stock_item_id' => $stock->id,
            'quantity'      => $addedQty,
            'remaining_qty' => $addedQty,
            'unit_cost'     => $data['unit_cost'] ?? $stock->unit_cost,
            'expiry_date'   => $expiryDate,
            'received_by'   => $performer,
        ]);

        $newBalance = round((float) $stock->quantity + $addedQty, 2);
        $updates = ['quantity' => $newBalance];

        if (isset($data['unit_cost'])) {
            $updates['unit_cost'] = $data['unit_cost'];
        }

        $currentExpiry = $stock->expiry_date
            ? Carbon::parse($stock->expiry_date)->toDateString()
            : null;

        if ($expiryDate && (!$currentExpiry || $expiryDate < $currentExpiry)) {
            $updates['expiry_date'] = $expiryDate;
        }

        $stock->update($updates);

        $this->inventory->recordMovement(
            $stock->fresh(),
            +$addedQty,
            $newBalance,
            'stock_in',
            "Batch #{$batch->id}",
            $performer
        );

        return response()->json($batch, 201);
    }

    public function updateCount(Request $request, int $id)
    {
        $request->validate(['quantity' => 'required|numeric|min:0']);

        $item = StockItem::findOrFail($id);
        $oldQty = (float) $item->quantity;
        $newQty = (float) $request->quantity;
        $diff = round($newQty - $oldQty, 2);
        $performer = $request->user()?->name ?? 'Staff';

        $updates = ['quantity' => $newQty];
        if ($newQty <= 0) {
            $updates['expiry_date'] = null;
        }
        $item->update($updates);

        StockCount::create([
            'stock_item_id' => $item->id,
            'counted_qty'   => $newQty,
            'counted_by'    => $performer,
        ]);

        // Keep active FIFO batch quantities synced when physical count drops
        if ($newQty <= 0) {
            StockBatch::where('stock_item_id', $item->id)
                ->where('remaining_qty', '>', 0)
                ->update(['remaining_qty' => 0]);
        } else {
            $activeBatches = StockBatch::where('stock_item_id', $item->id)
                ->where('remaining_qty', '>', 0)
                ->orderByRaw('expiry_date IS NULL ASC')
                ->orderBy('expiry_date', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            $totalBatchQty = (float) $activeBatches->sum('remaining_qty');
            if ($totalBatchQty > $newQty) {
                $toTrim = round($totalBatchQty - $newQty, 2);
                foreach ($activeBatches as $batch) {
                    if ($toTrim <= 0) {
                        break;
                    }
                    $deduct = min($toTrim, (float) $batch->remaining_qty);
                    $batch->update(['remaining_qty' => round((float) $batch->remaining_qty - $deduct, 2)]);
                    $toTrim = round($toTrim - $deduct, 2);
                }
            }
        }

        if (abs($diff) >= 0.01) {
            $this->inventory->recordMovement(
                $item->fresh(),
                $diff,
                $newQty,
                'closing_count',
                'Physical Count Adjustment',
                $performer
            );
        }

        return response()->json($item->fresh());
    }

    public function updateExpiry(Request $request, int $id)
    {
        $data = $request->validate([
            'expiry_date'         => 'nullable|date',
            'expiry_warning_days' => 'nullable|integer|min:0|max:365',
            'shelf_life_days'     => 'nullable|integer|min:1|max:365',
        ]);

        $item = StockItem::findOrFail($id);

        if (array_key_exists('expiry_warning_days', $data) && $data['expiry_warning_days'] !== null) {
            $item->expiry_warning_days = (int) $data['expiry_warning_days'];
        }

        if (array_key_exists('shelf_life_days', $data)) {
            $item->shelf_life_days = $data['shelf_life_days'] !== '' ? $data['shelf_life_days'] : null;
        }

        $newExpiry = $data['expiry_date'] ?? null;
        if (!$newExpiry && !empty($item->shelf_life_days) && (float) $item->quantity > 0 && !$item->expiry_date) {
            $newExpiry = now()->addDays((int) $item->shelf_life_days)->toDateString();
        }

        $item->expiry_date = $newExpiry;
        $item->save();

        $earliestBatch = StockBatch::where('stock_item_id', $item->id)
            ->where('remaining_qty', '>', 0)
            ->orderByRaw('expiry_date IS NULL ASC')
            ->orderBy('expiry_date', 'asc')
            ->orderBy('id', 'asc')
            ->first();

        if ($earliestBatch) {
            $earliestBatch->update(['expiry_date' => $newExpiry]);
        }

        return response()->json($item->fresh());
    }
}