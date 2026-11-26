<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RecoveryPack;
use App\Models\Setting;
use App\Services\RecoveryPackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class RecoveryPackController extends Controller
{
    public function estimate(RecoveryPackService $svc)
    {
        return $svc->calculateEstimate();
    }

    public function index()
    {
        return RecoveryPack::latest()->get();
    }

    public function generate(Request $request, RecoveryPackService $svc)
    {
        $sendEmail = (bool) $request->input('send_email', false);
        $simulateFail = (bool) $request->input('simulate_fail', false);
        return response()->json($svc->generatePack('manual', $sendEmail, $simulateFail), 201);
    }

    public function retryEmail(RecoveryPackService $svc, int $id)
    {
        $pack = RecoveryPack::findOrFail($id);
        return response()->json($svc->sendPackWithRetry($pack));
    }

    public function download(int $id)
    {
        $pack = RecoveryPack::findOrFail($id);
        return response()->download(storage_path('app/' . $pack->file_ref));
    }

    public function getSettings()
    {
        return [
            'owner_email' => Setting::where('setting_key', 'owner_email')->value('value'),
            'send_time' => Setting::where('setting_key', 'send_time')->value('value'),
        ];
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            'owner_email' => 'required|email',
            'send_time' => 'required|string',
            'pack_password' => 'nullable|string|min:4',
        ]);
        Setting::updateOrCreate(['setting_key' => 'owner_email'], ['value' => $request->owner_email]);
        Setting::updateOrCreate(['setting_key' => 'send_time'], ['value' => $request->send_time]);
        if ($request->filled('pack_password')) {
            Setting::updateOrCreate(
                ['setting_key' => 'pack_password'],
                ['value' => Crypt::encryptString($request->pack_password)]
            );
        }
        return response()->json(['message' => 'Recovery settings saved.']);
    }

    public function getDiscounts()
    {
        return [
            'tumbler_discount' => (float) (Setting::where('setting_key', 'tumbler_discount')->value('value') ?? 10),
            'senior_discount_pct' => (float) (Setting::where('setting_key', 'senior_discount_pct')->value('value') ?? 20),
            'pwd_discount_pct' => (float) (Setting::where('setting_key', 'pwd_discount_pct')->value('value') ?? 20),
        ];
    }

    public function updateDiscounts(Request $request)
    {
        $request->validate([
            'tumbler_discount' => 'required|numeric|min:0',
            'senior_discount_pct' => 'required|numeric|min:0|max:100',
            'pwd_discount_pct' => 'required|numeric|min:0|max:100',
        ]);
        Setting::updateOrCreate(['setting_key' => 'tumbler_discount'], ['value' => (string) $request->tumbler_discount]);
        Setting::updateOrCreate(['setting_key' => 'senior_discount_pct'], ['value' => (string) $request->senior_discount_pct]);
        Setting::updateOrCreate(['setting_key' => 'pwd_discount_pct'], ['value' => (string) $request->pwd_discount_pct]);
        return response()->json(['message' => 'Discount rates updated.']);
    }
}