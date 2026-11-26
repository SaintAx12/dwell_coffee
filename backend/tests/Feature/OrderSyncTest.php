<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_saves_order_items_using_the_orders_uuid_primary_key(): void
    {
        $user = User::create([
            'name' => 'Cashier',
            'employee_no' => 'CASHIER-1',
            'password' => 'password',
        ]);
        $menuItem = MenuItem::create([
            'name' => 'Coffee',
            'category' => 'Coffee',
            'price' => 100,
        ]);
        $orderId = 'c75b7f95-4f69-4ecc-8b78-37b980e9eb02';

        $response = $this->actingAs($user)->postJson('/api/orders/sync', [
            'orders' => [[
                'order_id' => $orderId,
                'items' => [[
                    'menu_item_id' => $menuItem->id,
                    'quantity' => 1,
                    'price' => 100,
                ]],
                'total' => 100,
            ]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('orders.0.order_id', $orderId)
            ->assertJsonPath('orders.0.items.0.order_id', $orderId);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'menu_item_id' => $menuItem->id,
        ]);
        $this->assertSame($orderId, Order::with('items')->firstOrFail()->items->first()->order_id);
    }
}
