<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Shipping\ConfirmShipmentDeliveryAction;
use App\Actions\Shipping\RecordShipmentArrivalAction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * فاز ۱۰ — بند ۳.۱۰ سند و پاسخ مدیر (دور ۲، پرسش ۷ و ۸).
 *
 * ادعای مرکزی: وضعیتِ سفارش از روی محموله‌ها **محاسبه** می‌شود، نه دستی نوشته.
 * `partially_delivered` در فاز ۰ ساخته شد و تا اینجا هیچ نویسنده‌ای نداشت.
 */
class ShipmentDeliveryTest extends TestCase
{
    use DatabaseTransactions;

    private User $buyer;

    private User $supplier;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->buyer = User::factory()->create(['mobile' => '09121110000']);
        $this->supplier = User::factory()->create(['mobile' => '09122220000']);

        $this->order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::ORDER_NUMBER => 'DLV-'.Str::random(8),
            Order::STATUS => Order::STATUS_SHIPPED,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::FINAL_AMOUNT => 1_000_000,
        ]);

        OrderItem::factory()->create([
            'order_id' => $this->order->id,
            'supplier_user_id' => $this->supplier->id,
        ]);
    }

    private function makeShipment(array $overrides = []): Shipment
    {
        $shipment = Shipment::create([
            Shipment::ORDER_ID => $this->order->id,
            Shipment::WEIGHT => 1000,
            Shipment::SHIPPING_COST => 0,
            Shipment::STATUS => Shipment::STATUS_SHIPPED,
        ]);

        $shipment->forceFill(array_merge([
            Shipment::WAYBILL_REF => 'WB-'.Str::random(5),
            Shipment::DRIVER_MOBILE => '09123330000',
            Shipment::ACTUAL_WEIGHT => 1000,
            Shipment::REGISTERED_AT => now(),
        ], $overrides))->save();

        return $shipment->fresh();
    }

    private function arrive(Shipment $shipment): Shipment
    {
        return app(RecordShipmentArrivalAction::class)
            ->execute($shipment, Shipment::ARRIVAL_DRIVER_LINK);
    }

    // ── رسیدن ────────────────────────────────────────────────────────────────

    #[Test]
    public function arrival_starts_the_confirmation_deadline(): void
    {
        $shipment = $this->arrive($this->makeShipment());

        $this->assertNotNull($shipment->{Shipment::ARRIVED_AT});
        $this->assertSame(Shipment::STATUS_ARRIVED, $shipment->{Shipment::STATUS});
        $this->assertNotNull($shipment->{Shipment::DELIVERY_DEADLINE});
        $this->assertSame(5, (int) $shipment->{Shipment::DELIVERY_DEADLINE_HOURS},
            'پیش‌فرض بند ۴.۵ پنج ساعت است');
    }

    #[Test]
    public function a_shipment_with_no_waybill_cannot_arrive(): void
    {
        // یک لینکِ لو رفته نباید مهلتِ تحویلِ باری را شروع کند که هنوز در
        // کارخانه است — پنج ساعت بعد همان بار «عودت‌شده» می‌شد.
        $shipment = $this->makeShipment([Shipment::REGISTERED_AT => null]);

        $this->expectExceptionMessage(__('shipments.not_shipped_yet'));
        $this->arrive($shipment);
    }

    #[Test]
    public function the_signed_driver_link_needs_no_authentication(): void
    {
        $shipment = $this->makeShipment();

        $url = URL::temporarySignedRoute('shipments.arrived', now()->addDay(), ['shipment' => $shipment->id]);

        $this->getJson($url)->assertOk();
        $this->assertNotNull($shipment->fresh()->{Shipment::ARRIVED_AT});
    }

    #[Test]
    public function an_unsigned_arrival_request_is_refused(): void
    {
        $shipment = $this->makeShipment();

        $this->getJson("/api/shipments/{$shipment->id}/arrived")->assertForbidden();
        $this->assertNull($shipment->fresh()->{Shipment::ARRIVED_AT});
    }

    // ── تأیید تحویل ──────────────────────────────────────────────────────────

    #[Test]
    public function confirming_every_shipment_marks_the_order_delivered(): void
    {
        $first = $this->arrive($this->makeShipment());
        $second = $this->arrive($this->makeShipment());

        $action = app(ConfirmShipmentDeliveryAction::class);
        $action->execute($first, $this->buyer->id);

        $this->assertSame(Order::STATUS_PARTIALLY_DELIVERED, $this->order->fresh()->{Order::STATUS},
            'با یکی از دو محموله، سفارش تحویلِ جزئی است');

        $action->execute($second, $this->buyer->id);

        $this->assertSame(Order::STATUS_DELIVERED, $this->order->fresh()->{Order::STATUS});
    }

    #[Test]
    public function delivery_cannot_be_confirmed_before_arrival(): void
    {
        $shipment = $this->makeShipment();

        $this->expectExceptionMessage(__('shipments.not_arrived_yet'));
        app(ConfirmShipmentDeliveryAction::class)->execute($shipment, $this->buyer->id);
    }

    #[Test]
    public function only_the_payer_may_confirm_from_the_panel(): void
    {
        $shipment = $this->arrive($this->makeShipment());
        $stranger = User::factory()->create();

        Sanctum::actingAs($stranger);
        $this->postJson("/api/profile/shipments/{$shipment->id}/confirm-delivery")
            ->assertForbidden();

        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/profile/shipments/{$shipment->id}/confirm-delivery")
            ->assertOk();

        $this->assertNotNull($shipment->fresh()->{Shipment::DELIVERED_AT});
    }

    #[Test]
    public function a_second_confirmation_is_a_no_op(): void
    {
        $shipment = $this->arrive($this->makeShipment());
        $action = app(ConfirmShipmentDeliveryAction::class);

        $action->execute($shipment, $this->buyer->id);
        $first = $shipment->fresh()->{Shipment::DELIVERED_AT};

        $action->execute($shipment->fresh(), $this->buyer->id);

        $this->assertEquals($first, $shipment->fresh()->{Shipment::DELIVERED_AT},
            'تأیید دوباره نباید مهرِ زمانی را جابه‌جا کند');
    }

    // ── انقضای مهلت ⇒ عودت ───────────────────────────────────────────────────

    #[Test]
    public function an_unconfirmed_shipment_returns_to_origin_after_the_deadline(): void
    {
        $shipment = $this->arrive($this->makeShipment());
        $shipment->forceFill([Shipment::DELIVERY_DEADLINE => now()->subMinute()])->save();

        Artisan::call('shipments:expire-delivery');

        $shipment->refresh();
        $this->assertNotNull($shipment->{Shipment::RETURNED_AT});
        $this->assertSame(Shipment::STATUS_RETURNED, $shipment->{Shipment::STATUS});
        $this->assertSame(Order::STATUS_RETURNED_TO_ORIGIN, $this->order->fresh()->{Order::STATUS});
    }

    #[Test]
    public function a_confirmed_shipment_survives_the_expiry_sweep(): void
    {
        $shipment = $this->arrive($this->makeShipment());
        app(ConfirmShipmentDeliveryAction::class)->execute($shipment, $this->buyer->id);
        $shipment->forceFill([Shipment::DELIVERY_DEADLINE => now()->subMinute()])->save();

        Artisan::call('shipments:expire-delivery');

        $this->assertNull($shipment->fresh()->{Shipment::RETURNED_AT});
        $this->assertSame(Order::STATUS_DELIVERED, $this->order->fresh()->{Order::STATUS});
    }

    #[Test]
    public function one_returned_leg_does_not_erase_another_legs_delivery(): void
    {
        // محموله‌ی عودتی «بلاتکلیف» نیست، پس سفارشی که یکی‌اش تحویل و یکی‌اش
        // عودت شده کامل است — نه اینکه برای همیشه در تحویلِ جزئی بماند.
        $delivered = $this->arrive($this->makeShipment());
        $expiring = $this->arrive($this->makeShipment());

        app(ConfirmShipmentDeliveryAction::class)->execute($delivered, $this->buyer->id);
        $expiring->forceFill([Shipment::DELIVERY_DEADLINE => now()->subMinute()])->save();

        Artisan::call('shipments:expire-delivery');

        $this->assertSame(Shipment::STATUS_RETURNED, $expiring->fresh()->{Shipment::STATUS});
        $this->assertSame(Order::STATUS_DELIVERED, $this->order->fresh()->{Order::STATUS});
    }
}
