<?php

namespace Tests\Feature\Order;

use App\Constants\FinancialCodes;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\User;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Per-document authorization on OrderInvoiceController::download.
 *
 * A buyer's documents (initial / final / tax) must NOT be downloadable by other
 * parties on the order (supplier, eliteYAR) even though they pass the generic
 * `view` policy — only the buyer (placer) or an admin may fetch them.
 *
 * DatabaseTransactions: all writes roll back; the live DB is never mutated.
 */
class InvoiceAccessTest extends TestCase
{
    use DatabaseTransactions;

    private User $buyer;

    private User $eliteYAR;

    private User $supplier;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->create();
        $this->eliteYAR = User::factory()->create();

        $supplierRole = Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier', 'description' => 'Supplier']);
        $this->supplier = User::factory()->create();
        $this->supplier->addRole($supplierRole);

        // الیت‌یار در ستونِ *خودش* می‌نشیند، نه در `beneficiary_user_id`.
        //
        // این فیکسچر قبلاً الیت‌یار را در ستونِ ذی‌نفع می‌گذاشت. آن موقع فرقی
        // نمی‌کرد چون `partyRolesFor()` هر دو ستون را به یک مخاطب نگاشت می‌کرد —
        // و دقیقاً همان نگاشت بود که باعث می‌شد کارفرمای ذی‌نفع سندِ مالیاتیِ
        // شخصیِ الیت‌یار را بگیرد. طبق `StoreOrderRequest`، ذی‌نفع همیشه یک
        // **کارفرما**ست («باید کارفرمای approved همین الیت یار باشد»)، پس گذاشتنِ
        // الیت‌یار در آن ستون حالتی را می‌سازد که در محصول وجود ندارد.
        $this->order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::ELITE_YAR_USER_ID => $this->eliteYAR->id,
            Order::ORDER_NUMBER => 'INV-'.Str::random(12),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => 'paid',
        ]);

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $this->order->id,
            OrderItem::SUPPLIER_USER_ID => $this->supplier->id,
        ]);
    }

    private function url(string $type = 'tax'): string
    {
        return "/api/profile/orders/{$this->order->id}/invoice/{$type}";
    }

    #[Test]
    public function consultant_cannot_download_the_buyers_tax_invoice(): void
    {
        Sanctum::actingAs($this->eliteYAR);
        $this->get($this->url('tax'))->assertForbidden();
    }

    #[Test]
    public function supplier_cannot_download_the_buyers_tax_invoice(): void
    {
        Sanctum::actingAs($this->supplier);
        $this->get($this->url('tax'))->assertForbidden();
    }

    #[Test]
    public function buyer_can_download_their_own_tax_invoice(): void
    {
        // Mock the generator so no real PDF/Blade view is needed.
        $pdf = \Mockery::mock();
        $pdf->shouldReceive('download')->andReturn(response('PDF-BYTES', 200, ['Content-Type' => 'application/pdf']));
        $this->mock(InvoiceGenerator::class)
            ->shouldReceive('download')
            ->andReturn($pdf);

        Sanctum::actingAs($this->buyer);
        $this->get($this->url('tax'))->assertOk();
    }

    /**
     * پاسخِ مدیریت به پرسشِ ۱۶ (۱۴۰۵/۰۷/۱۳، گزینه‌ی الف): در سفارشِ لغوشده فقط رسیدِ
     * بازگشتِ وجه دانلود می‌شود؛ فاکتورهای خرید بسته‌اند و دکمه‌ی دانلود هم به آن‌ها اشاره نمی‌کند.
     */
    #[Test]
    public function a_cancelled_order_only_serves_the_refund_receipt(): void
    {
        $pdf = \Mockery::mock();
        $pdf->shouldReceive('download')->andReturn(response('PDF-BYTES', 200, ['Content-Type' => 'application/pdf']));
        $this->mock(InvoiceGenerator::class)->shouldReceive('download')->andReturn($pdf);

        $this->order->update([Order::STATUS => Order::STATUS_CANCELLED]);
        Sanctum::actingAs($this->buyer);

        foreach (['initial', 'final', 'tax'] as $slug) {
            $this->getJson($this->url($slug))->assertStatus(422);
        }
        $this->get($this->url('refund'))->assertOk();

        // بی‌رسیدِ بازگشت (سفارشِ پرداخت‌نشده‌ی لغوشده) دکمه‌ای نیست؛ با رسید، دکمه همان رسید است.
        $this->getJson("/api/profile/orders/{$this->order->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.download_invoice.active', false);

        Invoice::create([
            'order_id' => $this->order->id,
            'user_id' => $this->buyer->id,
            'document_code' => FinancialCodes::DOC_REFUND_RECEIPT,
            'invoice_number' => 'RF-'.Str::random(8),
            'amount' => 0,
            'file_path' => 'x.pdf',
        ]);

        $this->getJson("/api/profile/orders/{$this->order->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.download_invoice.active', true)
            ->assertJsonPath('data.actions.download_invoice.url', url($this->url('refund')));
    }
}
