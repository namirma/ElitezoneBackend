<?php

namespace Tests\Feature\Order;

use App\Actions\Financial\ProcessWeighbridgeResultAction;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ثبت بارنامه توسط تأمین‌کننده — فاز ۷، بند ۳.۷.
 *
 * تا این فاز، وزنِ باسکول از یک اندپوینتِ **ادمین** می‌آمد و بارنامه از مسیری
 * که فاز ۰ حذفش کرده بود. یعنی وضعیت «در حال حمل» را ادمین می‌نوشت در حالی که
 * کسی که واقعاً بار را تحویل راننده می‌دهد تأمین‌کننده است. این تست‌ها مالکیتِ
 * تازه، idempotency بارنامه، و قاعده‌ی «تسویه فقط با ثبتِ آخرین محموله» را پین
 * می‌کنند.
 */
class RegisterShipmentTest extends TestCase
{
    use DatabaseTransactions;

    private User $supplier;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = User::factory()->create();
        // `OrderPolicy::manageAsSupplier` هم نقش را می‌خواهد و هم مالکیتِ آیتم.
        $this->supplier->addRole(
            \Laratrust\Models\Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier'])
        );
        Wallet::create([Wallet::USER_ID => $this->supplier->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->order = Order::create([
            Order::PLACER_USER_ID => User::factory()->create()->id,
            Order::ORDER_NUMBER => 'SHP-'.Str::random(10),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::FINAL_AMOUNT => 1_000_000,
        ]);

        // وزنِ سفارش لازم است چون یکی از سه ماشه‌ی تسویه «مجموع وزن‌ها به وزنِ
        // سفارش رسید» است (بند ۸.۲). بدون آن، ماشه هرگز از این راه فعال نمی‌شود.
        $this->order->forceFill([Order::WEIGHT_BEFORE_SCALE => 1500])->save();

        // مالکیت تأمین‌کننده از روی آیتم‌های سفارش خوانده می‌شود
        // (`OrderPolicy::manageAsSupplier`).
        OrderItem::factory()->create([
            'order_id' => $this->order->id,
            'supplier_user_id' => $this->supplier->id,
        ]);
    }

    private function makeShipment(): Shipment
    {
        return Shipment::create([
            Shipment::ORDER_ID => $this->order->id,
            Shipment::WEIGHT => 1000,
            Shipment::SHIPPING_COST => 0,
            Shipment::STATUS => Shipment::STATUS_PENDING,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'waybill_ref' => 'WB-'.Str::random(6),
            'driver_name' => 'رضا محمدی',
            'driver_national_code' => '1234567890',
            'driver_mobile' => '09121234567',
            'vehicle_plate' => '12ب345-11',
            'vehicle_type' => 'کمپرسی',
            'actual_weight' => 990,
        ], $overrides);
    }

    private function url(Shipment $shipment): string
    {
        return "/api/profile/orders/{$this->order->id}/shipments/{$shipment->id}/waybill";
    }

    /** تسویه را بی‌اثر می‌کند تا این تست‌ها فقط ثبتِ بارنامه را بسنجند. */
    private function muteSettlement(): void
    {
        $mock = Mockery::mock(ProcessWeighbridgeResultAction::class);
        $mock->shouldReceive('execute')->andReturn(['status' => 'equal']);
        $this->app->instance(ProcessWeighbridgeResultAction::class, $mock);
    }

    #[Test]
    public function the_supplier_registers_the_waybill_driver_and_weight_together(): void
    {
        $this->muteSettlement();
        $shipment = $this->makeShipment();

        Sanctum::actingAs($this->supplier);
        $this->postJson($this->url($shipment), $this->payload())->assertOk();

        $fresh = $shipment->fresh();

        $this->assertNotNull($fresh->{Shipment::REGISTERED_AT});
        $this->assertSame('رضا محمدی', $fresh->{Shipment::DRIVER_NAME});
        $this->assertSame('12ب345-11', $fresh->{Shipment::VEHICLE_PLATE});
        $this->assertSame(990.0, (float) $fresh->{Shipment::ACTUAL_WEIGHT});
        $this->assertSame(Shipment::STATUS_SHIPPED, $fresh->{Shipment::STATUS});
    }

    #[Test]
    public function a_driver_from_the_book_fills_the_waybill_and_is_linked_to_the_shipment(): void
    {
        // ⚠️ رگرسیونی که این می‌گیرد: جدولِ `drivers` و ستونِ
        // `shipments.driver_id` از فاز ۹ وجود داشتند و تأمین‌کننده می‌توانست
        // راننده ثبت و موبایلش را با OTP تأیید کند — ولی هیچ‌چیز در کلِ `app/`
        // هرگز `driver_id` را نمی‌نوشت. دفترچه ساخته شده بود و به هیچ‌جا وصل
        // نبود؛ هر بارنامه راننده را از نو تایپ می‌کرد.
        $this->muteSettlement();
        $shipment = $this->makeShipment();

        $driver = Driver::create([
            Driver::CARRIER_USER_ID => $this->supplier->id,
            Driver::FULL_NAME => 'کریم رضایی',
            Driver::MOBILE => '09129998877',
            Driver::NATIONAL_CODE => '0987654321',
            Driver::PLATE_NUMBER => '44الف222-33',
            Driver::VEHICLE_TYPE => 'تریلی',
            Driver::IS_ACTIVE => true,
        ]);

        Sanctum::actingAs($this->supplier);
        $this->postJson($this->url($shipment), [
            'waybill_ref' => 'WB-BOOK-1',
            'driver_id' => $driver->id,
            'actual_weight' => 990,
        ])->assertOk();

        $fresh = $shipment->fresh();

        $this->assertSame($driver->id, (int) $fresh->{Shipment::DRIVER_ID});
        // ستون‌ها اسنپ‌شات‌اند: ویرایشِ بعدیِ دفترچه نباید بارنامه‌ی صادرشده را
        // بازنویسی کند.
        $this->assertSame('کریم رضایی', $fresh->{Shipment::DRIVER_NAME});
        $this->assertSame('09129998877', $fresh->{Shipment::DRIVER_MOBILE});
        $this->assertSame('44الف222-33', $fresh->{Shipment::VEHICLE_PLATE});
    }

    #[Test]
    public function another_carriers_driver_cannot_be_used(): void
    {
        $this->muteSettlement();
        $shipment = $this->makeShipment();

        $foreign = Driver::create([
            Driver::CARRIER_USER_ID => User::factory()->create()->id,
            Driver::FULL_NAME => 'راننده‌ی دیگری',
            Driver::MOBILE => '09120000001',
            Driver::PLATE_NUMBER => '11ب111-11',
            Driver::VEHICLE_TYPE => 'کمپرسی',
            Driver::IS_ACTIVE => true,
        ]);

        Sanctum::actingAs($this->supplier);
        $this->postJson($this->url($shipment), [
            'waybill_ref' => 'WB-FOREIGN',
            'driver_id' => $foreign->id,
            'actual_weight' => 990,
        ])->assertStatus(422);

        $this->assertNull($shipment->fresh()->{Shipment::REGISTERED_AT});
    }

    #[Test]
    public function the_free_text_path_still_works_without_a_driver_id(): void
    {
        // مسیرِ متنی عمداً نشکسته: بارنامه‌ی کاغذی همیشه راننده‌ی ثبت‌شده ندارد.
        $this->muteSettlement();
        $shipment = $this->makeShipment();

        Sanctum::actingAs($this->supplier);
        $this->postJson($this->url($shipment), $this->payload())->assertOk();

        $fresh = $shipment->fresh();
        $this->assertNull($fresh->{Shipment::DRIVER_ID});
        $this->assertSame('رضا محمدی', $fresh->{Shipment::DRIVER_NAME});
    }

    #[Test]
    public function a_second_registration_of_the_same_shipment_is_refused(): void
    {
        $this->muteSettlement();
        $shipment = $this->makeShipment();

        Sanctum::actingAs($this->supplier);
        $this->postJson($this->url($shipment), $this->payload())->assertOk();

        // بارنامه یک سندِ رسمی است؛ ثبتِ دوباره شماره‌ای را که به راننده داده
        // شده بی‌صدا باطل می‌کرد.
        $first = $shipment->fresh()->{Shipment::WAYBILL_REF};

        $this->postJson($this->url($shipment), $this->payload(['waybill_ref' => 'WB-OTHER']))
            ->assertStatus(409);

        $this->assertSame($first, $shipment->fresh()->{Shipment::WAYBILL_REF});
    }

    #[Test]
    public function another_supplier_cannot_register_this_shipment(): void
    {
        $this->muteSettlement();
        $shipment = $this->makeShipment();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson($this->url($shipment), $this->payload())->assertForbidden();

        $this->assertNull($shipment->fresh()->{Shipment::REGISTERED_AT});
    }

    #[Test]
    public function a_shipment_from_a_different_order_is_refused(): void
    {
        $this->muteSettlement();

        $otherOrder = Order::create([
            Order::PLACER_USER_ID => User::factory()->create()->id,
            Order::ORDER_NUMBER => 'OTH-'.Str::random(10),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::FINAL_AMOUNT => 1_000_000,
        ]);
        $foreign = Shipment::create([
            Shipment::ORDER_ID => $otherOrder->id,
            Shipment::WEIGHT => 100,
            Shipment::SHIPPING_COST => 0,
            Shipment::STATUS => Shipment::STATUS_PENDING,
        ]);

        Sanctum::actingAs($this->supplier);
        $this->postJson($this->url($foreign), $this->payload())->assertStatus(404);
    }

    #[Test]
    public function an_unpaid_order_cannot_receive_a_waybill(): void
    {
        $this->muteSettlement();
        $this->order->forceFill([Order::PAYMENT_STATUS => Order::PAYMENT_PENDING])->save();
        $shipment = $this->makeShipment();

        Sanctum::actingAs($this->supplier);
        $this->postJson($this->url($shipment), $this->payload())->assertStatus(422);

        $this->assertNull($shipment->fresh()->{Shipment::REGISTERED_AT});
    }

    #[Test]
    public function the_weight_must_be_positive(): void
    {
        $this->muteSettlement();
        $shipment = $this->makeShipment();

        // وزنِ صفر داده‌ی خراب است، نه «بارِ خالی»: تسویه روی آن کل مبلغ را به
        // خریدار عودت می‌دهد و یک بدهیِ ساختگی برای تأمین‌کننده می‌سازد.
        Sanctum::actingAs($this->supplier);
        $this->postJson($this->url($shipment), $this->payload(['actual_weight' => 0]))
            ->assertStatus(422);
    }

    // ── قاعده‌ی «تسویه فقط با آخرین محموله» ──────────────────────────────────

    #[Test]
    public function settlement_does_not_run_while_a_leg_is_still_unregistered(): void
    {
        $mock = Mockery::mock(ProcessWeighbridgeResultAction::class);
        $mock->shouldReceive('execute')->never();
        $this->app->instance(ProcessWeighbridgeResultAction::class, $mock);

        $first = $this->makeShipment();
        $this->makeShipment(); // دومی ثبت‌نشده می‌ماند

        Sanctum::actingAs($this->supplier);
        $this->postJson($this->url($first), $this->payload())->assertOk();
    }

    #[Test]
    public function marking_a_shipment_final_settles_even_with_legs_outstanding(): void
    {
        // ماشه‌ی صریح — بدونِ آن، کم‌تحویلی هرگز تسویه نمی‌شد: مجموع وزن به
        // وزنِ سفارش نمی‌رسد و محموله‌ی ثبت‌نشده هم باقی می‌ماند.
        $mock = Mockery::mock(ProcessWeighbridgeResultAction::class);
        $mock->shouldReceive('execute')
            ->once()
            ->with(Mockery::type(Order::class), 400.0)
            ->andReturn(['status' => 'deficit']);
        $this->app->instance(ProcessWeighbridgeResultAction::class, $mock);

        $first = $this->makeShipment();
        $this->makeShipment(); // ثبت‌نشده می‌ماند و هرگز هم نخواهد شد

        Sanctum::actingAs($this->supplier);
        $this->postJson($this->url($first), $this->payload([
            'actual_weight' => 400,
            'is_final' => true,
        ]))->assertOk();
    }

    #[Test]
    public function the_last_registration_settles_with_the_summed_weight(): void
    {
        $mock = Mockery::mock(ProcessWeighbridgeResultAction::class);
        $mock->shouldReceive('execute')
            ->once()
            // مجموع و نه وزنِ آخرین لِگ — وگرنه سفارشِ چندمحموله‌ای با کسری
            // کاذبِ اندازه‌ی بقیه‌ی محموله‌ها تسویه می‌شد.
            ->with(Mockery::type(Order::class), 1500.0)
            ->andReturn(['status' => 'equal']);
        $this->app->instance(ProcessWeighbridgeResultAction::class, $mock);

        $first = $this->makeShipment();
        $second = $this->makeShipment();

        Sanctum::actingAs($this->supplier);
        $this->postJson($this->url($first), $this->payload(['actual_weight' => 600]))->assertOk();
        $this->postJson($this->url($second), $this->payload(['actual_weight' => 900]))->assertOk();
    }

    #[Test]
    public function the_order_collects_every_waybill_number_once_complete(): void
    {
        $this->muteSettlement();

        $first = $this->makeShipment();
        $second = $this->makeShipment();

        Sanctum::actingAs($this->supplier);
        $this->postJson($this->url($first), $this->payload(['waybill_ref' => 'WB-A']))->assertOk();

        // تا کامل‌نشدن، ستون سفارش دست‌نخورده می‌ماند.
        $this->assertNull($this->order->fresh()->{Order::WAYBILL_REF});

        $this->postJson($this->url($second), $this->payload(['waybill_ref' => 'WB-B']))->assertOk();

        $ref = (string) $this->order->fresh()->{Order::WAYBILL_REF};
        $this->assertStringContainsString('WB-A', $ref);
        $this->assertStringContainsString('WB-B', $ref);
    }

    #[Test]
    public function the_admin_weighbridge_endpoint_is_gone(): void
    {
        // بند ۱۶: پلِ موقتِ فاز ۰ با ساخته‌شدن جایگزینش حذف می‌شود.
        $this->assertFalse(
            collect(app('router')->getRoutes())->contains(
                fn ($route) => str_contains($route->uri(), 'weighbridge')
            ),
            'هیچ روتِ باسکولی نباید باقی مانده باشد.'
        );
    }
}
