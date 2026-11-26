<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockItem;
use App\Models\WasteLog;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WasteController extends Controller
{
    public function __construct(private InventoryService $inventory)
    {
    }

    public function index()
    {
        return response()->json(
            WasteLog::latest()->limit(50)->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'stock_item_id' => 'nullable|exists:stock_items,id',
            'item_name'     => 'required|string|max:255',
            'quantity'      => 'required|numeric|min:0.01',
            'reason'        => 'required|in:spoiled,spilled,unsold,expired,other',
        ]);

        return DB::transaction(function () use ($request, $data) {
            $performer = $request->user()?->name ?? 'Staff';

            $waste = WasteLog::create([
                'stock_item_id' => $data['stock_item_id'] ?? null,
                'item_name'     => $data['item_name'],
                'quantity'      => $data['quantity'],
                'reason'        => $data['reason'],
                'logged_by'     => $performer,
            ]);

            if (!empty($data['stock_item_id'])) {
                $stockItem = StockItem::lockForUpdate()->find($data['stock_item_id']);

                if ($stockItem) {
                    $deductQty = (float) $data['quantity'];
                    $newQty = max(0, round((float) $stockItem->quantity - $deductQty, 2));
                    $stockItem->quantity = $newQty;

                    if (Schema::hasTable('stock_batches')) {
                        $toDeduct = $deductQty;
                        $batches = DB::table('stock_batches')
                            ->where('stock_item_id', $stockItem->id)
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
                            DB::table('stock_batches')
                                ->where('id', $batch->id)
                                ->update(['remaining_qty' => round((float) $batch->remaining_qty - $take, 2)]);
                            $toDeduct = round($toDeduct - $take, 2);
                        }

                        $nextExpiry = DB::table('stock_batches')
                            ->where('stock_item_id', $stockItem->id)
                            ->where('remaining_qty', '>', 0)
                            ->whereNotNull('expiry_date')
                            ->orderBy('expiry_date', 'asc')
                            ->value('expiry_date');

                        $stockItem->expiry_date = $newQty > 0 ? $nextExpiry : null;
                    } elseif ($newQty <= 0) {
                        $stockItem->expiry_date = null;
                    }

                    $stockItem->save();

                    $this->inventory->recordMovement(
                        $stockItem,
                        -$deductQty,
                        $newQty,
                        'waste',
                        'Waste: ' . ucfirst($data['reason']),
                        $performer
                    );
                }
            }

            return response()->json($waste, 201);
        });
    }
}