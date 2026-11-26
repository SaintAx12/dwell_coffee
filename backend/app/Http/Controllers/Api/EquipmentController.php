<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use Illuminate\Http\Request;

class EquipmentController extends Controller
{
    public function index()
    {
        return Equipment::orderBy('id')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'quantity' => 'required|integer|min:1',
            'purchase_date' => 'nullable|date',
            'value' => 'nullable|numeric|min:0',
            'value_type' => 'required|in:exact,estimated',
            'photo' => 'nullable|image|max:5120',
        ]);

        $photoPath = $request->hasFile('photo')
            ? $request->file('photo')->store('equipment', 'public')
            : null;

        return response()->json(Equipment::create([
            'name' => $data['name'],
            'quantity' => $data['quantity'],
            'purchase_date' => $data['purchase_date'] ?? null,
            'value' => $data['value'] ?? null,
            'value_type' => $data['value_type'],
            'photo_path' => $photoPath,
        ]), 201);
    }
}