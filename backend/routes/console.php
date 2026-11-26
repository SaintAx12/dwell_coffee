<?php

use App\Models\Backup;
use App\Models\Equipment;
use App\Models\MenuItem;
use App\Models\Setting;
use App\Models\StockItem;
use App\Services\RecoveryPackService;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Schedule::call(function () {
    $snapshot = json_encode([
        'menu_items' => MenuItem::all()->toArray(),
        'stock_items' => StockItem::all()->toArray(),
        'equipment' => Equipment::all()->toArray(),
    ]);

    Backup::create([
        'type' => 'scheduled',
        'size' => strlen($snapshot),
        'status' => 'completed',
        'checksum' => hash('sha256', $snapshot),
        'snapshot_json' => $snapshot,
    ]);
})->dailyAt('23:55');

$sendTime = '22:00';
try {
    if (Schema::hasTable('settings')) {
        $sendTime = Setting::where('setting_key', 'send_time')->value('value') ?? '22:00';
    }
} catch (\Throwable $e) {}

Schedule::call(function (RecoveryPackService $svc) {
    $svc->generatePack('scheduled', true);
})->dailyAt($sendTime);