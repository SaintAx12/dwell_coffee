<?php

namespace Database\Seeders;

use App\Models\Equipment;
use App\Models\MenuItem;
use App\Models\Setting;
use App\Models\StockBatch;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::create(['name' => 'Dwell Owner', 'employee_no' => 'EMP-001', 'email' => 'owner@dwellcoffee.ph', 'role' => 'owner', 'is_active' => true, 'password' => Hash::make('owner123')]);
        User::create(['name' => 'Barista Cashier', 'employee_no' => 'EMP-002', 'role' => 'cashier', 'is_active' => true, 'password' => Hash::make('cashier123')]);

        $milk = StockItem::create(['name' => 'Fresh Milk', 'unit' => 'bottles', 'quantity' => 3, 'min_level' => 5, 'unit_cost' => 120, 'expiry_date' => now()->addDays(2)]);
        $beans = StockItem::create(['name' => 'Arabica Beans', 'unit' => 'kg', 'quantity' => 4, 'min_level' => 2, 'unit_cost' => 850, 'expiry_date' => now()->addDays(30)]);
        $cookies = StockItem::create(['name' => 'Consignment Cookies', 'unit' => 'pcs', 'quantity' => 10, 'min_level' => 4, 'unit_cost' => 45, 'expiry_date' => now()->addDays(1)]);
        StockItem::create(['name' => 'Disposable Cups', 'unit' => 'pcs', 'quantity' => 85, 'min_level' => 30, 'unit_cost' => 6, 'expiry_date' => null]);

        StockBatch::create(['stock_item_id' => $milk->id, 'quantity' => 3, 'remaining_qty' => 3, 'unit_cost' => 120, 'expiry_date' => now()->addDays(2), 'received_by' => 'Dwell Owner']);
        StockBatch::create(['stock_item_id' => $beans->id, 'quantity' => 4, 'remaining_qty' => 4, 'unit_cost' => 850, 'expiry_date' => now()->addDays(30), 'received_by' => 'Dwell Owner']);
        StockBatch::create(['stock_item_id' => $cookies->id, 'quantity' => 10, 'remaining_qty' => 10, 'unit_cost' => 45, 'expiry_date' => now()->addDays(1), 'received_by' => 'Dwell Owner']);

        $menuItems = [
            ['name' => 'Espresso', 'category' => 'Specialty Coffee', 'price' => 80, 'stock_item_id' => null],
            ['name' => 'Long Black', 'category' => 'Specialty Coffee', 'price' => 100, 'stock_item_id' => null],
            ['name' => 'Flat White', 'category' => 'Specialty Coffee', 'price' => 115, 'stock_item_id' => null],
            ['name' => 'Cappuccino', 'category' => 'Specialty Coffee', 'price' => 115, 'stock_item_id' => null],
            ['name' => 'Latte', 'category' => 'Specialty Coffee', 'price' => 120, 'stock_item_id' => null],
            ['name' => 'Spanish Latte', 'category' => 'Altered Coffee', 'price' => 130, 'stock_item_id' => null],
            ['name' => 'Sea Salt Latte', 'category' => 'Altered Coffee', 'price' => 140, 'stock_item_id' => null],
            ['name' => 'Caramel Latte', 'category' => 'Altered Coffee', 'price' => 140, 'stock_item_id' => null],
            ['name' => 'Mocha', 'category' => 'Altered Coffee', 'price' => 140, 'stock_item_id' => null],
            ['name' => 'Dirty Matcha', 'category' => 'Altered Coffee', 'price' => 160, 'stock_item_id' => null],
            ['name' => 'Matcha Latte', 'category' => 'Non-Coffee', 'price' => 140, 'stock_item_id' => null],
            ['name' => 'Consignment Cookie', 'category' => 'Pastries', 'price' => 65, 'stock_item_id' => $cookies->id],
        ];
        foreach ($menuItems as $m) {
            MenuItem::create($m);
        }

        Equipment::create(['name' => 'Espresso Machine', 'quantity' => 1, 'purchase_date' => '2023-01-15', 'value' => 60000, 'value_type' => 'exact']);
        Equipment::create(['name' => 'Coffee Grinder', 'quantity' => 2, 'purchase_date' => '2023-01-15', 'value' => 8000, 'value_type' => 'estimated']);
        Equipment::create(['name' => 'Under-counter Chiller', 'quantity' => 1, 'purchase_date' => '2023-02-01', 'value' => 15000, 'value_type' => 'exact']);
        Equipment::create(['name' => 'Counter Tablet Stand', 'quantity' => 1, 'purchase_date' => '2023-03-10', 'value' => null, 'value_type' => 'estimated']);

        Setting::create(['setting_key' => 'owner_email', 'value' => 'owner@dwellcoffee.ph']);
        Setting::create(['setting_key' => 'send_time', 'value' => '22:00']);
        Setting::create(['setting_key' => 'pack_password', 'value' => Crypt::encryptString('dwell2026')]);
        Setting::create(['setting_key' => 'tumbler_discount', 'value' => '10']);
        Setting::create(['setting_key' => 'senior_discount_pct', 'value' => '20']);
        Setting::create(['setting_key' => 'pwd_discount_pct', 'value' => '20']);
    }
}