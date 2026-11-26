<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Equipment;
use App\Models\MenuItem;
use App\Models\StockItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BackupController extends Controller
{
    public function index()
    {
        return Backup::latest()->get(['id', 'type', 'size', 'status', 'checksum', 'created_at']);
    }

    public function store()
    {
        $snapshot = json_encode([
            'menu_items' => MenuItem::all()->toArray(),
            'stock_items' => StockItem::all()->toArray(),
            'equipment' => Equipment::all()->toArray(),
        ]);

        return response()->json(Backup::create([
            'type' => 'manual',
            'size' => strlen($snapshot),
            'status' => 'completed',
            'checksum' => hash('sha256', $snapshot),
            'snapshot_json' => $snapshot,
        ]), 201);
    }

    public function restore(Request $request, int $id)
    {
        $backup = Backup::findOrFail($id);
        $computedHash = hash('sha256', (string) $backup->snapshot_json);
        $data = json_decode((string) $backup->snapshot_json, true);

        if (
            !hash_equals($backup->checksum, $computedHash) ||
            !is_array($data) ||
            !isset($data['menu_items'], $data['stock_items'], $data['equipment'])
        ) {
            return response()->json([
                'message' => 'Damaged backup refused; current data remain unchanged.',
            ], 422);
        }

        DB::transaction(function () use ($data) {
            Equipment::truncate();
            foreach ($data['equipment'] as $row) Equipment::create($row);
            foreach ($data['stock_items'] as $row) StockItem::updateOrCreate(['id' => $row['id']], $row);
            foreach ($data['menu_items'] as $row) MenuItem::updateOrCreate(['id' => $row['id']], $row);
        });

        AuditLog::create([
            'user_name' => $request->user()->name,
            'action' => 'restore_backup',
            'target' => "Backup #{$backup->id}",
            'details' => "Restored database from backup created at {$backup->created_at}",
        ]);

        return response()->json(['message' => 'Database restored from backup.']);
    }
}