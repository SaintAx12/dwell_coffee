<?php

namespace App\Services;

use App\Mail\RecoveryPackMail;
use App\Models\Equipment;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\RecoveryPack;
use App\Models\Setting;
use App\Models\StockItem;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

class RecoveryPackService
{
    public function calculateEstimate(): array
    {
        $equipment = Equipment::all();
        $stock = StockItem::all();

        $equipTotal = $equipment->sum(fn ($e) => ($e->value ?? 0) * $e->quantity);
        $stockTotal = $stock->sum(fn ($s) => ($s->unit_cost ?? 0) * $s->quantity);

        $unvaluedEquip = $equipment->whereNull('value')->pluck('name')->map(fn ($n) => "$n (Equipment)")->all();
        $unvaluedStock = $stock->whereNull('unit_cost')->pluck('name')->map(fn ($n) => "$n (Stock)")->all();
        $unvalued = array_values(array_merge($unvaluedEquip, $unvaluedStock));

        return [
            'equipment_total' => $equipTotal,
            'stock_total' => $stockTotal,
            'rebuild_estimate' => $equipTotal + $stockTotal,
            'unvalued_items' => $unvalued,
        ];
    }

    public function generatePack(string $type = 'manual', bool $sendEmail = false, bool $simulateEmailFail = false): RecoveryPack
    {
        $equipment = Equipment::all();
        $stock = StockItem::all();
        $menu = MenuItem::where('is_active', true)->get();

        $orders30d = Order::whereNull('void_reason')
            ->where('created_at', '>=', now()->subDays(30))
            ->orderBy('created_at')
            ->get();

        $dailyBreakdown = $orders30d->groupBy(fn ($o) => $o->created_at->format('Y-m-d'))
            ->map(fn ($group, $date) => [
                'date' => $date,
                'orders' => $group->count(),
                'cash' => $group->where('payment_method', 'cash')->sum('total'),
                'gcash' => $group->where('payment_method', 'gcash')->sum('total'),
                'total' => $group->sum('total'),
            ])->values()->all();

        $sales30dCount = $orders30d->count();
        $sales30dTotal = $orders30d->sum('total');
        $estimateData = $this->calculateEstimate();
        $rebuildEstimate = $estimateData['rebuild_estimate'];
        $unvaluedItems = $estimateData['unvalued_items'];

        Storage::makeDirectory('recovery_packs');
        $tempPdf = storage_path('app/recovery_packs/temp_' . uniqid() . '.pdf');
        Pdf::loadView('pdf.recovery_pack', compact(
            'equipment', 'stock', 'menu', 'dailyBreakdown', 'sales30dCount', 'sales30dTotal', 'rebuildEstimate', 'unvaluedItems'
        ))->save($tempPdf);

        $fileName = 'DwellCoffee_RecoveryPack_' . now()->format('Ymd_His') . '.zip';
        $relativePath = 'recovery_packs/' . $fileName;
        $fullZipPath = storage_path('app/' . $relativePath);

        $encryptedPass = Setting::where('setting_key', 'pack_password')->value('value');
        $plainPassword = $encryptedPass ? Crypt::decryptString($encryptedPass) : 'dwell2026';

        $photosOmitted = false;
        $maxBytes = 10 * 1024 * 1024;

        $zip = new ZipArchive();
        if ($zip->open($fullZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $zip->setPassword($plainPassword);
            $zip->addFile($tempPdf, 'DwellCoffee_Recovery_Summary.pdf');
            $zip->setEncryptionName('DwellCoffee_Recovery_Summary.pdf', ZipArchive::EM_AES_256);

            $runningSize = filesize($tempPdf);
            foreach ($equipment as $eq) {
                if (!$eq->photo_path) continue;
                $photoFullPath = storage_path('app/public/' . $eq->photo_path);
                if (!file_exists($photoFullPath)) continue;

                $photoSize = filesize($photoFullPath);
                if ($runningSize + $photoSize <= $maxBytes) {
                    $entryName = 'equipment_photos/' . basename($eq->photo_path);
                    $zip->addFile($photoFullPath, $entryName);
                    $zip->setEncryptionName($entryName, ZipArchive::EM_AES_256);
                    $runningSize += $photoSize;
                } else {
                    $photosOmitted = true;
                }
            }
            $zip->close();
        }
        @unlink($tempPdf);

        $pack = RecoveryPack::create([
            'type' => $type,
            'size' => filesize($fullZipPath),
            'status' => 'generated',
            'attempts' => 0,
            'photos_omitted' => $photosOmitted,
            'file_ref' => $relativePath,
            'checksum' => hash_file('sha256', $fullZipPath),
        ]);

        if ($sendEmail) {
            $this->sendPackWithRetry($pack, $simulateEmailFail);
        }

        return $pack->fresh();
    }

    public function sendPackWithRetry(RecoveryPack $pack, bool $simulateFail = false): RecoveryPack
    {
        $ownerEmail = Setting::where('setting_key', 'owner_email')->value('value');
        if (!$ownerEmail) {
            $pack->update(['status' => 'failed', 'attempts' => 1, 'error_message' => 'No owner email configured.']);
            return $pack;
        }

        $lastError = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                if ($simulateFail) {
                    throw new \RuntimeException('Simulated SMTP failure.');
                }
                Mail::to($ownerEmail)->send(new RecoveryPackMail($pack));
                $pack->update(['status' => 'emailed', 'attempts' => $attempt, 'error_message' => null]);
                return $pack;
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        $pack->update(['status' => 'failed', 'attempts' => 3, 'error_message' => $lastError]);
        return $pack;
    }
}