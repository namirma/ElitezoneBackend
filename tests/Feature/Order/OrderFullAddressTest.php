<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Models\Address;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `delivery_address.full_address` و `shipments[].destination.full_address` همیشه null بودند:
 * هر دو `->address` می‌خواندند، ولی ستونِ آدرس `postal_address` است.
 */
class OrderFullAddressTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function the_order_and_its_shipment_carry_the_postal_address(): void
    {
        $buyer = User::factory()->create();
        $address = Address::create([
            Address::USER_ID => $buyer->id,
            Address::POSTAL_ADDRESS => 'اصفهان، خیابان چهارباغ، کوچه ۱۲',
            Address::RECEIVER_NAME => 'گیرنده',
            Address::RECEIVER_MOBILE => '09120000000',
        ]);
        $order = Order::create([
            Order::PLACER_USER_ID => $buyer->id,
            Order::PAYER_USER_ID => $buyer->id,
            Order::ADDRESS_ID => $address->id,
            Order::ORDER_NUMBER => 'ADDR-'.Str::random(10),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);
        OrderItem::factory()->create([OrderItem::ORDER_ID => $order->id]);
        Shipment::create([
            Shipment::ORDER_ID => $order->id,
            Shipment::ADDRESS_ID => $address->id,
            Shipment::STATUS => Shipment::STATUS_SHIPPED,
        ]);

        Sanctum::actingAs($buyer);
        $this->getJson("/api/profile/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.delivery_address.full_address', 'اصفهان، خیابان چهارباغ، کوچه ۱۲')
            ->assertJsonPath('data.shipments.0.destination.full_address', 'اصفهان، خیابان چهارباغ، کوچه ۱۲');
    }
}
