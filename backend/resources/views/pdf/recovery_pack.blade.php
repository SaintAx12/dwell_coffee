<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
            h1 { color: #2c1e16; border-bottom: 2px solid #2c1e16; padding-bottom: 6px; font-size: 18px; }
            h3 { margin-top: 16px; color: #2c1e16; font-size: 13px; }
            table { width: 100%; border-collapse: collapse; margin-top: 6px; }
            th, td { border: 1px solid #bbb; padding: 5px; text-align: left; }
            th { background: #f2ece9; }
            .box { background: #f9f6f4; border: 1px solid #ccc; padding: 10px; margin: 10px 0; }
        </style>
    </head>
    <body>
        <h1>Dwell Coffee — Off-Site Disaster Recovery Pack</h1>
        <p>Generated: {{ now()->format('Y-m-d H:i') }} | Ampayon, Butuan City</p>

        <div class="box">
            <strong>Estimated Rebuild Cost (Equipment + Stock):</strong>
            PHP {{ number_format($rebuildEstimate, 2) }}
            @if(count($unvaluedItems) > 0)
                <br><small><strong>Items listed as "no value" (excluded):</strong> {{ implode(', ', $unvaluedItems) }}</small>
            @endif
        </div>

        <h3>1. Equipment Register ({{ $equipment->count() }} items)</h3>
        <table>
            <thead><tr><th>Item</th><th>Qty</th><th>Purchase Date</th><th>Unit Value</th><th>Type</th></tr></thead>
            <tbody>
                @foreach($equipment as $eq)
                <tr>
                    <td>{{ $eq->name }}</td>
                    <td>{{ $eq->quantity }}</td>
                    <td>{{ $eq->purchase_date ?? 'N/A' }}</td>
                    <td>{{ $eq->value !== null ? 'PHP ' . number_format($eq->value, 2) : 'No value' }}</td>
                    <td>{{ strtoupper($eq->value_type) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <h3>2. Stock Snapshot ({{ $stock->count() }} items)</h3>
        <table>
            <thead><tr><th>Item</th><th>Quantity</th><th>Unit</th><th>Unit Cost</th><th>Next Expiry</th></tr></thead>
            <tbody>
                @foreach($stock as $st)
                <tr>
                    <td>{{ $st->name }}</td>
                    <td>{{ $st->quantity }}</td>
                    <td>{{ $st->unit }}</td>
                    <td>{{ $st->unit_cost !== null ? 'PHP ' . number_format($st->unit_cost, 2) : 'No value' }}</td>
                    <td>{{ $st->expiry_date ? $st->expiry_date->format('Y-m-d') : 'N/A' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <h3>3. 30-Day Sales Summary by Day & Payment Method</h3>
        <p>Total Valid Orders (30 Days): <strong>{{ $sales30dCount }}</strong> |
        Total Revenue: <strong>PHP {{ number_format($sales30dTotal, 2) }}</strong></p>
        <table>
            <thead><tr><th>Date</th><th>Orders</th><th>Cash Sales</th><th>GCash Sales</th><th>Daily Total</th></tr></thead>
            <tbody>
                @forelse($dailyBreakdown as $day)
                <tr>
                    <td>{{ $day['date'] }}</td>
                    <td>{{ $day['orders'] }}</td>
                    <td>PHP {{ number_format($day['cash'], 2) }}</td>
                    <td>PHP {{ number_format($day['gcash'], 2) }}</td>
                    <td><strong>PHP {{ number_format($day['total'], 2) }}</strong></td>
                </tr>
                @empty
                <tr><td colspan="5">No valid orders recorded in the last 30 days.</td></tr>
                @endforelse
            </tbody>
        </table>

        <h3>4. Active Menu & Prices ({{ $menu->count() }} items)</h3>
        <table>
            <thead><tr><th>Item</th><th>Category</th><th>Price</th></tr></thead>
            <tbody>
                @foreach($menu as $item)
                <tr>
                    <td>{{ $item->name }}</td>
                    <td>{{ $item->category }}</td>
                    <td>PHP {{ number_format($item->price, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </body>
</html>