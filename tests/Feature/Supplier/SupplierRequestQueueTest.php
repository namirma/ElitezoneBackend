<?php

declare(strict_types=1);

namespace Tests\Feature\Supplier;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupplierRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * دو کاری که تأمین‌کننده **نباید** خودش انجام بدهد، ولی باید بتواند بخواهد.
 *
 *   لغوِ سفارش — بند ۵.۳ لغوِ اجباری را تنها اختیارِ وضعیتیِ ادمین کرده و بند
 *   ۳.۶ آن را به «تیکت + دلیل» مشروط می‌کند. یک لغوِ خودسرویس هر دو را دور
 *   می‌زد، روی اقدامی که سفارشِ پرداخت‌شده را برمی‌گرداند.
 *
 *   افزودنِ کالا — کاتالوگ عمداً ادمین‌محور است؛ نوشتنِ مستقیم یعنی هر
 *   تأمین‌کننده نامِ کالای خودش را بسازد.
 *
 * مهم‌ترین چیزی که اینجا پین می‌شود: **پذیرشِ درخواستِ لغو هیچ سفارشی را لغو
 * نمی‌کند.** اگر می‌کرد، یک مسیرِ دومِ لغو بدونِ تیکت و دلیل ساخته می‌شد.
 */
class SupplierRequestQueueTest extends TestCase
{
    use DatabaseTransactions, GrantsAdminPermissions;

    private User $supplier;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = User::factory()->create();
        $this->supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));

        $this->admin = User::factory()->create();
        $this->admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($this->admin);
    }

    private function order(array $snapshot = []): Order
    {
        $order = Order::create([
            Order::PLACER_USER_ID => User::factory()->create()->id,
            Order::ORDER_NUMBER => 'SR-'.Str::random(8),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);

        if ($snapshot !== []) {
            $order->forceFill($snapshot)->save();
        }

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $order->id,
            'supplier_user_id' => $this->supplier->id,
        ]);

        return $order->fresh();
    }

    #[Test]
    public function a_cancellation_request_is_queued_and_the_order_is_untouched(): void
    {
        $order = $this->order();

        Sanctum::actingAs($this->supplier);
        $this->postJson("/api/profile/orders/{$order->id}/cancellation-request", [
            'reason' => 'کارخانه دچار قطعی برق شده و امکان بارگیری نیست.',
        ])->assertCreated();

        $this->assertDatabaseHas('supplier_requests', [
            SupplierRequest::ORDER_ID => $order->id,
            SupplierRequest::TYPE => SupplierRequest::TYPE_ORDER_CANCELLATION,
            SupplierRequest::STATUS => SupplierRequest::STATUS_PENDING,
        ]);

        // درخواست ثبت شد؛ سفارش هنوز دقیقاً همان است.
        $this->assertSame(Order::STATUS_PROCESSING, $order->fresh()->{Order::STATUS});
    }

    #[Test]
    public function approving_the_request_still_does_not_cancel_the_order(): void
    {
        // ⚠️ قلبِ این فایل. اگر پذیرش خودش لغو می‌کرد، یک مسیرِ دومِ لغو ساخته
        // می‌شد که شرطِ «تیکت + دلیل» بند ۳.۶ را ندارد — روی تنها اقدامی که
        // سفارشِ پرداخت‌شده را یک‌طرفه برمی‌گرداند.
        $order = $this->order();

        Sanctum::actingAs($this->supplier);
        $this->postJson("/api/profile/orders/{$order->id}/cancellation-request", [
            'reason' => 'موجودی انبار با سفارش نمی‌خواند.',
        ])->assertCreated();

        $requestId = SupplierRequest::query()->value(SupplierRequest::ID);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/supplier-requests/{$requestId}/approve", [
            'note' => 'تأیید شد؛ با تیکت پیگیری می‌شود.',
        ])->assertOk();

        $this->assertSame(SupplierRequest::STATUS_APPROVED, SupplierRequest::find($requestId)->{SupplierRequest::STATUS});
        $this->assertSame(Order::STATUS_PROCESSING, $order->fresh()->{Order::STATUS});
    }

    #[Test]
    public function a_settled_order_cannot_be_requested_for_cancellation(): void
    {
        // پس از باسکول پول جابه‌جا شده؛ برگرداندنش مسیر مرجوعی/داوری است.
        $order = $this->order([Order::WEIGHING_COMPLETED_AT => now()]);

        Sanctum::actingAs($this->supplier);
        $this->postJson("/api/profile/orders/{$order->id}/cancellation-request", [
            'reason' => 'می‌خواهم این سفارش لغو شود لطفاً.',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_second_pending_request_for_the_same_order_is_refused(): void
    {
        $order = $this->order();

        Sanctum::actingAs($this->supplier);
        $payload = ['reason' => 'دلیلی که به‌اندازه‌ی کافی بلند است.'];

        $this->postJson("/api/profile/orders/{$order->id}/cancellation-request", $payload)->assertCreated();
        $this->postJson("/api/profile/orders/{$order->id}/cancellation-request", $payload)->assertStatus(409);
    }

    #[Test]
    public function another_supplier_cannot_request_cancellation_of_this_order(): void
    {
        $order = $this->order();

        $stranger = User::factory()->create();
        $stranger->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));

        Sanctum::actingAs($stranger);
        $this->postJson("/api/profile/orders/{$order->id}/cancellation-request", [
            'reason' => 'دلیلی که به‌اندازه‌ی کافی بلند است.',
        ])->assertStatus(403);
    }

    #[Test]
    public function a_catalog_addition_is_queued_with_its_details(): void
    {
        Sanctum::actingAs($this->supplier);

        $this->postJson('/api/profile/supplier/requests/catalog-addition', [
            'product_name' => 'میلگرد آجدار A4',
            'variant_name' => 'سایز ۲۸',
            'specifications' => 'شاخه ۱۲ متری، استاندارد ملی',
            'reason' => 'این سایز را تولید می‌کنیم ولی در کاتالوگ نیست.',
        ])->assertCreated();

        $queued = SupplierRequest::query()
            ->where(SupplierRequest::TYPE, SupplierRequest::TYPE_CATALOG_ADDITION)
            ->firstOrFail();

        $this->assertSame('میلگرد آجدار A4', $queued->{SupplierRequest::PAYLOAD}['product_name']);
        $this->assertSame('سایز ۲۸', $queued->{SupplierRequest::PAYLOAD}['variant_name']);
    }

    #[Test]
    public function rejecting_without_a_note_is_refused(): void
    {
        Sanctum::actingAs($this->supplier);
        $this->postJson('/api/profile/supplier/requests/catalog-addition', [
            'product_name' => 'ورق سیاه',
            'reason' => 'این محصول را هم تولید می‌کنیم.',
        ])->assertCreated();

        $requestId = SupplierRequest::query()->value(SupplierRequest::ID);

        Sanctum::actingAs($this->admin);
        // «رد شد» بدونِ دلیل همان بن‌بستی است که در ردِ درخواستِ تأمین‌کنندگی بود.
        $this->postJson("/api/admin/supplier-requests/{$requestId}/reject", [])->assertStatus(422);

        $this->postJson("/api/admin/supplier-requests/{$requestId}/reject", [
            'note' => 'این کالا در دسته‌ی دیگری از قبل هست.',
        ])->assertOk();

        Sanctum::actingAs($this->supplier);
        $row = collect($this->getJson('/api/profile/supplier/requests')->assertOk()->json('data'))->first();

        $this->assertSame(SupplierRequest::STATUS_REJECTED, $row[SupplierRequest::STATUS]);
        $this->assertSame('این کالا در دسته‌ی دیگری از قبل هست.', $row[SupplierRequest::REVIEW_NOTE]);
    }

    #[Test]
    public function a_reviewed_request_cannot_be_reviewed_again_or_withdrawn(): void
    {
        Sanctum::actingAs($this->supplier);
        $this->postJson('/api/profile/supplier/requests/catalog-addition', [
            'product_name' => 'نبشی',
            'reason' => 'این محصول را هم تولید می‌کنیم.',
        ])->assertCreated();

        $requestId = SupplierRequest::query()->value(SupplierRequest::ID);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/supplier-requests/{$requestId}/approve")->assertOk();
        // تصمیم و نامِ بررسی‌کننده سابقه‌اند، نه فیلدِ قابلِ ویرایش.
        $this->postJson("/api/admin/supplier-requests/{$requestId}/reject", ['note' => 'نظر عوض شد.'])
            ->assertStatus(409);

        Sanctum::actingAs($this->supplier);
        $this->deleteJson("/api/profile/supplier/requests/{$requestId}")->assertStatus(422);
    }
}
