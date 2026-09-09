<?php

namespace Tests\Feature\Supplier;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * گاردهای عملیاتِ تأمین‌کننده روی سفارش.
 *
 * گذارهای وضعیت‌دارِ این کنترلر همگی زیر قفل بررسی می‌شوند، ولی دو متد از قلم
 * افتاده بودند:
 *
 *   - `issueEspadanaCommand` هیچ گاردی نداشت — روی سفارشِ لغوشده هم اجرا می‌شد و
 *     هر بار صدا زدن شناسه را با `time()`ِ تازه بازنویسی می‌کرد، یعنی مرجعی که به
 *     سیستم بیرونی داده شده بی‌صدا بی‌اعتبار می‌شد.
 *   - `attachPaymentReceipt` گاردِ وضعیت نداشت و فایلِ قبلی را هنگام جایگزینی روی
 *     دیسکِ `financial` یتیم رها می‌کرد.
 */
class SupplierOrderActionGuardsTest extends TestCase
{
    use DatabaseTransactions;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = User::factory()->create();
        $this->supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));
    }

    private function order(string $status, string $paymentStatus = Order::PAYMENT_PAID): Order
    {
        $order = Order::create([
            Order::PLACER_USER_ID => User::factory()->create()->id,
            Order::ORDER_NUMBER => 'GRD-'.Str::random(10),
            Order::STATUS => $status,
            Order::FINAL_AMOUNT => 1_000_000,
            Order::PAYMENT_STATUS => $paymentStatus,
        ]);

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $order->id,
            OrderItem::SUPPLIER_USER_ID => $this->supplier->id,
        ]);

        return $order;
    }

    /*
     * سه تستِ «دستور بارگیری اسپادانا» این‌جا بودند و همراهِ خودِ قابلیت حذف
     * شدند — تصمیمِ مدیریت ۲۰۲۶-۰۹-۰۹. تستِ گاردِ یک اندپوینتِ حذف‌شده، تستِ
     * چیزی است که وجود ندارد.
     */

    // ── Payment receipt ─────────────────────────────────────────────────────

    #[Test]
    public function a_receipt_cannot_be_attached_before_the_order_is_paid(): void
    {
        Storage::fake('financial');

        $order = $this->order(Order::STATUS_PROCESSING, Order::PAYMENT_PENDING);
        Sanctum::actingAs($this->supplier);

        $this->postJson("/api/profile/orders/{$order->id}/receipt", [
            'receipt' => UploadedFile::fake()->create('r.pdf', 40, 'application/pdf'),
        ])->assertStatus(400);

        $this->assertNull($order->fresh()->{Order::PAYMENT_RECEIPT_REF});
    }

    #[Test]
    public function replacing_a_receipt_deletes_the_previous_file_instead_of_orphaning_it(): void
    {
        Storage::fake('financial');

        $order = $this->order(Order::STATUS_PROCESSING);
        Sanctum::actingAs($this->supplier);

        $this->postJson("/api/profile/orders/{$order->id}/receipt", [
            'receipt' => UploadedFile::fake()->create('first.pdf', 40, 'application/pdf'),
        ])->assertOk();

        $first = $order->fresh()->{Order::PAYMENT_RECEIPT_REF};
        Storage::disk('financial')->assertExists($first);

        $this->postJson("/api/profile/orders/{$order->id}/receipt", [
            'receipt' => UploadedFile::fake()->create('second.pdf', 40, 'application/pdf'),
        ])->assertOk();

        $second = $order->fresh()->{Order::PAYMENT_RECEIPT_REF};

        $this->assertNotSame($first, $second);
        Storage::disk('financial')->assertExists($second);
        Storage::disk('financial')->assertMissing($first);
    }

    // ── Status filter ───────────────────────────────────────────────────────

    #[Test]
    public function an_unknown_status_filter_is_a_validation_error_not_an_empty_list(): void
    {
        Sanctum::actingAs($this->supplier);

        // یک تایپو قبلاً لیستِ خالی می‌داد، که از دید پنل با «سفارشی نداری» یکسان است.
        $this->getJson('/api/profile/supplier/orders?status=procesing')
            ->assertStatus(422);
    }

    #[Test]
    public function a_real_status_still_filters(): void
    {
        $this->order(Order::STATUS_PROCESSING);
        Sanctum::actingAs($this->supplier);

        $this->getJson('/api/profile/supplier/orders?status='.Order::STATUS_PROCESSING)
            ->assertOk();
    }
}
