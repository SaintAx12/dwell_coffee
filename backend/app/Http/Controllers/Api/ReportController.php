<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\WasteLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function daily()
    {
        $todayValid = Order::with('items')
            ->whereNull('void_reason')
            ->whereDate('created_at', today())
            ->get();

        return [
            'order_count' => $todayValid->count(),
            'total_sales' => $todayValid->sum('total'),
            'cash_sales' => $todayValid->where('payment_method', 'cash')->sum('total'),
            'gcash_sales' => $todayValid->where('payment_method', 'gcash')->sum('total'),
            'total_discounts' => $todayValid->sum('discount_amount'),
            'tumbler_count' => $todayValid->where('discount_type', 'tumbler')->count(),
            'recent_orders' => Order::with('items')->latest()->take(20)->get(),
        ];
    }

    public function analytics(Request $request)
    {
        $from = $request->query('from', now()->subDays(7)->toDateString());
        $to = $request->query('to', now()->toDateString());

        $orders = Order::whereNull('void_reason')
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->get();

        $topItems = OrderItem::whereIn('order_id', $orders->pluck('order_id'))
            ->select('item_name', DB::raw('SUM(quantity) as total_qty'), DB::raw('SUM(quantity * unit_price) as revenue'))
            ->groupBy('item_name')
            ->orderByDesc('total_qty')
            ->get();

        $wasteEntries = WasteLog::with('stockItem')
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->get();

        $wasteByReason = $wasteEntries->groupBy('reason')->map(fn ($group, $reason) => [
            'reason' => $reason,
            'total_qty' => $group->sum('quantity'),
            'estimated_cost' => $group->sum(fn ($w) => $w->quantity * ($w->stockItem?->unit_cost ?? 0)),
        ])->values();

        $tumblerOrders = $orders->where('discount_type', 'tumbler');

        return [
            'period_sales' => $orders->sum('total'),
            'period_orders' => $orders->count(),
            'top_items' => $topItems,
            'waste_by_reason' => $wasteByReason,
            'total_waste_qty' => $wasteEntries->sum('quantity'),
            'total_waste_cost' => $wasteByReason->sum('estimated_cost'),
            'cups_avoided' => $tumblerOrders->count(),
            'tumbler_savings' => $tumblerOrders->sum('discount_amount'),
            'total_discounts_given' => $orders->sum('discount_amount'),
        ];
    }

    public function auditLogs()
    {
        return AuditLog::latest()->take(50)->get();
    }
}