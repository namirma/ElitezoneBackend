<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Financial\ProcessWeighbridgeResultAction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * وزنِ اشتباه‌تایپ‌شده قفل بود.
 *
 * ‏`RegisterShipmentAction` عمداً idempotent است — بارنامه یک سندِ رسمی است و
 * شماره‌ی داده‌شده به راننده نباید بی‌صدا باطل شود. ولی همان گارد یعنی
 * تأمین‌کننده‌ای که یک رقم جا انداخته هیچ راهی نداشت: ۴۰۹ می‌گرفت و باید منتظرِ
 * ادمین می‌ماند، روی عددی که مستقیماً مبلغِ تسویه را تعیین می‌کند.
 *
 * مرزِ ایمنی صریح است: تا وقتی تسویه اجرا نشده، اصلاح یک ویرایشِ داده است؛ پس
 * از آن پول روی همان عدد جابه‌جا شده و بازنویسی‌اش سفارش را از دفترِ کیف پول
 * جدا می‌کند.
 */
class CorrectShipmentWeightTest extends TestCase
{
    use DatabaseTransactions;

    private User $supplier;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = User::factory()->create();
        $this->supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));
        Wallet::create([Wallet::USER_ID => $this->supplier->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->order = Order::create([
            Order::PLACER_USER_ID => User::factory()->create()->id,
            Order::ORDER_NUMBER => 'CW-'.Str::random(8),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);
        $this->order->forceFill([Order::WEIGHT_BEFORE_SCALE => 10_000])->save();

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $this->order->id,
            'supplier_user_id' => $this->supplier->id,
        ]);
    }

    private function muteSettlement(): void
    {
        $mock = Mockery::mock(ProcessWeighbridgeResultAction::class);
        $mock->shouldReceive('execute')->andReturn(['status' => 'equal']);
        $this->app->instance(ProcessWeighbridgeResultAction::class, $mock);
    }

    private function registeredShipment(float $weight = 500): Shipment
    {
        return Shipment::create([
            Shipment::ORDER_ID => $this->order->id,
            Shipment::STATUS => Shipment::STATUS_SHIPPED,
        ])->forceFill([
            Shipment::WAYBILL_REF => 'WB-'.Str::random(6),
            Shipment::DRIVER_NAME => 'راننده',
            Shipment::VEHICLE_PLATE => '11ب111-11',
            Shipment::ACTUAL_WEIGHT => $weight,
            Shipment::REGISTERED_AT => now(),
        ]);
    }

    private function url(Shipment $shipment): string
    {
        return "/api/profile/orders/{$this->order->id}/shipments/{$shipment->id}/weight";
    }

    #[Test]
    public function the_supplier_can_fix_a_mistyped_weight_before_settlement(): void
    {
        $this->muteSettlement();
        $shipment = $this->registeredShipment(500);
        $shipment->save();

        Sanctum::actingAs($this->supplier);
        $this->patchJson($this->url($shipment), ['actual_weight' => 5000])->assertOk();

        $this->assertSame(5000.0, (float) $shipment->fresh()->{Shipment::ACTUAL_WEIGHT});
    }

    #[Test]
    public function the_weight_is_locked_once_the_money_has_moved(): void
    {
        $shipment = $this->registeredShipment(500);
        $shipment->save();

        // تسویه اجرا شده: POOM/PTSM محاسبه و کیف‌پول‌ها جابه‌جا شده‌اند.
        $this->order->forceFill([Order::WEIGHING_COMPLETED_AT => now()])->save();

        Sanctum::actingAs($this->supplier);
        $this->patchJson($this->url($shipment), ['actual_weight' => 5000])->assertStatus(409);

        $this->assertSame(500.0, (float) $shipment->fresh()->{Shipment::ACTUAL_WEIGHT});
    }

    #[Test]
    public function a_shipment_with_no_waybill_has_no_weight_to_correct(): void
    {
        $shipment = Shipment::create([
            Shipment::ORDER_ID => $this->order->id,
            Shipment::STATUS => Shipment::STATUS_PENDING,
        ]);

        Sanctum::actingAs($this->supplier);
        $this->patchJson($this->url($shipment), ['actual_weight' => 5000])->assertStatus(422);
    }

    #[Test]
    public function another_supplier_cannot_correct_this_weight(): void
    {
        $shipment = $this->registeredShipment(500);
        $shipment->save();

        $stranger = User::factory()->create();
        $stranger->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));

        Sanctum::actingAs($stranger);
        $this->patchJson($this->url($shipment), ['actual_weight' => 5000])->assertStatus(403);
    }

    #[Test]
    public function a_zero_weight_is_refused(): void
    {
        // همان قاعده‌ی ثبتِ بارنامه: صفر یعنی داده‌ی خراب، و تسویه روی وزنِ صفر
        // کلِ مبلغ را به خریدار عودت می‌دهد.
        $shipment = $this->registeredShipment(500);
        $shipment->save();

        Sanctum::actingAs($this->supplier);
        $this->patchJson($this->url($shipment), ['actual_weight' => 0])->assertStatus(422);
    }
}
