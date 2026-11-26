<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function __construct(private InventoryService $inventory)
    {
    }

    public function index(Request $request)
    {
        $query = Order::with('items')->latest();

        if ($request->boolean('today', true)) {
            $query->whereDate('created_at', now()->toDateString());
        }

        return response()->json($query->limit(50)->get());
    }

    public function sync(Request $request)
    {
        $rawOrders = $request->has('orders')
            ? $request->input('orders', [])
            : [$request->all()];

        if (empty($rawOrders)) {
            return response()->json(['message' => 'No order items provided.'], 422);
        }

        $performer = $request->user()?->name ?? 'Cashier';

        return DB::transaction(function () use ($rawOrders, $request, $performer) {
            $createdOrders = [];
            $orderCols = Schema::getColumnListing('orders');
            $itemCols = Schema::getColumnListing('order_items');

            foreach ($rawOrders as $payload) {
                $items = $payload['items'] ?? [];
                if (empty($items)) {
                    continue;
                }

                // Prevent duplicate sync if order_id or client_order_id already exists
                $incomingId = $payload['order_id'] ?? $payload['client_order_id'] ?? null;
                if ($incomingId) {
                    if (in_array('order_id', $orderCols, true)) {
                        $existing = Order::with('items')->where('order_id', $incomingId)->first();
                        if ($existing) {
                            $createdOrders[] = $existing;
                            continue;
                        }
                    }
                    if (in_array('client_order_id', $orderCols, true)) {
                        $existing = Order::with('items')->where('client_order_id', $incomingId)->first();
                        if ($existing) {
                            $createdOrders[] = $existing;
                            continue;
                        }
                    }
                }

                $orderUuid = $payload['order_id'] ?? (string) Str::uuid();
                $orderNumber = $payload['order_number']
                    ?? ('ORD-' . strtoupper(substr(str_replace('-', '', $orderUuid), 0, 8)));

                // 1. Atomically validate & deduct all recipe ingredients before saving order
                $this->inventory->deductForOrder($items, "Order #{$orderNumber}", $performer);

                // 2. Create the Order record matching all existing PostgreSQL columns
                $discountType = $payload['discount_type'] ?? 'none';
                $discountAmt = (float) ($payload['discount_amount'] ?? 0);
                $total = (float) ($payload['total'] ?? 0);
                $subtotal = (float) ($payload['subtotal'] ?? ($total + $discountAmt));
                $tendered = (float) ($payload['cash_tendered'] ?? $payload['amount_tendered'] ?? $payload['cash_received'] ?? $total);
                $changeAmt = (float) ($payload['change'] ?? $payload['change_amount'] ?? 0);

                $orderData = [];
                $candidateOrderFields = [
                    'order_id'        => $orderUuid,
                    'order_number'    => $orderNumber,
                    'receipt_number'  => $orderNumber,
                    'client_order_id' => $payload['client_order_id'] ?? $orderUuid,
                    'user_id'         => $request->user()?->id,
                    'cashier_id'      => $request->user()?->id,
                    'cashier_name'    => $performer,
                    'customer_type'   => $payload['customer_type'] ?? 'regular',
                    'has_tumbler'     => (bool) ($payload['has_tumbler'] ?? ($discountType === 'tumbler')),
                    'discount_type'   => $discountType,
                    'subtotal'        => $subtotal,
                    'discount_amount' => $discountAmt,
                    'total'           => $total,
                    'payment_method'  => $payload['payment_method'] ?? 'cash',
                    'cash_tendered'   => $tendered,
                    'amount_tendered' => $tendered,
                    'cash_received'   => $tendered,
                    'change'          => $changeAmt,
                    'change_amount'   => $changeAmt,
                    'status'          => 'completed',
                    'ordered_at'      => $payload['ordered_at'] ?? now(),
                    'synced_at'       => now(),
                ];

                foreach ($candidateOrderFields as $col => $val) {
                    if (in_array($col, $orderCols, true)) {
                        $orderData[$col] = $val;
                    }
                }

                $order = Order::create($orderData);

                // 3. Create OrderItem lines matching both qty and quantity columns
                $menuIds = collect($items)
                    ->map(fn ($line) => $line['menu_item_id'] ?? $line['id'] ?? null)
                    ->filter();

                $menuItems = MenuItem::whereIn('id', $menuIds)
                    ->get()
                    ->keyBy('id');

                foreach ($items as $line) {
                    $menuId = (int) ($line['menu_item_id'] ?? $line['id'] ?? 0);
                    $qty = (int) ($line['quantity'] ?? $line['qty'] ?? 1);
                    $menu = $menuItems->get($menuId);
                    $unitPrice = (float) ($line['unit_price'] ?? $line['price'] ?? $menu?->price ?? 0);
                    $itemName = $line['item_name'] ?? $line['name'] ?? $menu?->name ?? 'Menu Item';

                    $lineData = [];
                    $candidateItemFields = [
                        'order_id'     => $order->getKey(),
                        'menu_item_id' => $menuId ?: null,
                        'item_name'    => $itemName,
                        'name'         => $itemName,
                        'qty'          => $qty,
                        'quantity'     => $qty,
                        'unit_price'   => $unitPrice,
                        'price'        => $unitPrice,
                        'subtotal'     => round($unitPrice * $qty, 2),
                        'line_total'   => round($unitPrice * $qty, 2),
                    ];

                    foreach ($candidateItemFields as $col => $val) {
                        if (in_array($col, $itemCols, true)) {
                            $lineData[$col] = $val;
                        }
                    }

                    OrderItem::create($lineData);
                }

                $createdOrders[] = $order->load('items');
            }

            if ($request->has('orders')) {
                return response()->json([
                    'synced' => count($createdOrders),
                    'orders' => $createdOrders,
                ], 201);
            }

            return response()->json($createdOrders[0] ?? null, 201);
        });
    }

    public function void(Request $request, Order $order)
    {
        $orderCols = Schema::getColumnListing('orders');

        if (in_array('status', $orderCols, true) && in_array($order->status, ['voided', 'cancelled', 'refunded'], true)) {
            return response()->json(['message' => 'This order has already been cancelled/voided.'], 422);
        }

        $performer = $request->user()?->name ?? 'Staff';
        $reason = $request->input('reason', 'Cancelled / Refunded');

        return DB::transaction(function () use ($order, $orderCols, $performer, $reason) {
            $order->load('items');

            $orderNumber = $order->order_number ?? $order->receipt_number ?? $order->order_id ?? "ID-{$order->id}";
            $itemsPayload = $order->items->map(fn ($i) => [
                'menu_item_id' => $i->menu_item_id,
                'quantity'     => $i->quantity ?? $i->qty ?? 0,
            ])->all();

            // 1. Add all deducted recipe quantities back to stock & log movement
            $this->inventory->restoreForOrder($itemsPayload, "Void Order #{$orderNumber}", $performer);

            // 2. Mark order as voided
            $updates = [];
            if (in_array('status', $orderCols, true)) {
                $updates['status'] = 'voided';
            }
            if (in_array('voided_by', $orderCols, true)) {
                $updates['voided_by'] = $performer;
            }
            if (in_array('voided_at', $orderCols, true)) {
                $updates['voided_at'] = now();
            }
            if (in_array('void_reason', $orderCols, true)) {
                $updates['void_reason'] = $reason;
            }

            if (!empty($updates)) {
                $order->update($updates);
            }

            return response()->json($order->fresh()->load('items'));
        });
    }
}