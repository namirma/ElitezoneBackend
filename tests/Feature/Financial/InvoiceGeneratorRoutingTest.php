<?php

namespace Tests\Feature\Financial;

use App\Constants\FinancialCodes;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\DocumentSequenceService;
use App\Services\Invoice\InvoiceDataBuilder;
use App\Services\Invoice\InvoiceGenerator;
use App\Services\Invoice\InvoicePdfRenderer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Verifies InvoiceGenerator's semantic routing: each public scenario method emits
 * exactly the expected document codes and nothing else.
 *
 * Infrastructure dependencies (PDF rendering, sequence numbering, data building) are
 * mocked so the tests are fast, isolation is total, and no Blade view must exist.
 */
class InvoiceGeneratorRoutingTest extends TestCase
{
    use DatabaseTransactions;

    private InvoiceGenerator $generator;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        // Stub infrastructure so no file I/O or DB sequence calls occur.
        $this->mock(DocumentSequenceService::class)
            ->shouldReceive('generate')
            ->andReturnUsing(fn () => 'TEST-'.uniqid());

        $this->mock(InvoiceDataBuilder::class)
            ->shouldReceive('build')
            ->andReturn([]);

        $this->mock(InvoicePdfRenderer::class)
            ->shouldReceive('renderAndSave')
            ->andReturn('test/stub.pdf');

        $this->generator = app(InvoiceGenerator::class);

        $buyer = User::factory()->create();
        $supplier = User::factory()->create();

        $this->order = Order::create([
            Order::PLACER_USER_ID => $buyer->id,
            Order::ORDER_NUMBER => 'TEST-GEN-'.uniqid(),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::FINAL_AMOUNT => 10_000_000,
        ]);
        $this->order->forceFill([
            Order::OFFICIAL_PRICE => 0,
            Order::PAYMENT_TO_RESERVE => 2_000_000,
        ])->save();

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $this->order->id,
            OrderItem::SUPPLIER_USER_ID => $supplier->id,
            OrderItem::UNIT_PRICE => 500.0,
            OrderItem::TOTAL_PRICE => 10_000_000,
            OrderItem::QUANTITY => 20_000,
        ]);
    }

    // ─── Direct purchase ───────────────────────────────────────────────────────

    #[Test]
    public function direct_purchase_without_elite_yar_emits_three_docs(): void
    {
        $this->generator->generateDirectPurchaseDocs($this->order, withEliteYAR: false);

        $codes = $this->issuedCodes();
        $this->assertContains(FinancialCodes::DOC_RECEIPT_OWNER, $codes, 'Doc A missing');
        $this->assertContains(FinancialCodes::DOC_REPORT_SUPPLIER, $codes, 'Doc B missing');
        $this->assertContains(FinancialCodes::DOC_LOADING_ORDER, $codes, 'Doc C missing');
        $this->assertNotContains(FinancialCodes::DOC_ELITE_YAR_REPORT, $codes, 'Doc D must not appear without eliteYAR');
        $this->assertCount(3, $codes);
    }

    #[Test]
    public function direct_purchase_with_elite_yar_emits_four_docs(): void
    {
        $this->generator->generateDirectPurchaseDocs($this->order, withEliteYAR: true);

        $codes = $this->issuedCodes();
        $this->assertContains(FinancialCodes::DOC_ELITE_YAR_REPORT, $codes, 'Doc D must appear when withEliteYAR=true');
        $this->assertCount(4, $codes);
    }

    // ─── Reservation ──────────────────────────────────────────────────────────

    #[Test]
    public function reservation_emits_two_docs(): void
    {
        $this->generator->generateReservationDocs($this->order);

        $codes = $this->issuedCodes();
        $this->assertContains(FinancialCodes::DOC_RESERVE_RECEIPT, $codes, 'Doc E missing');
        $this->assertContains(FinancialCodes::DOC_RESERVE_REPORT, $codes, 'Doc F missing');
        $this->assertCount(2, $codes);
    }

    // ─── Reservation completion ───────────────────────────────────────────────

    #[Test]
    public function reservation_completion_emits_three_docs(): void
    {
        $this->generator->generateReservationCompletionDocs($this->order);

        $codes = $this->issuedCodes();
        $this->assertContains(FinancialCodes::DOC_FINAL_RECEIPT_OWNER, $codes, 'Doc G missing');
        $this->assertContains(FinancialCodes::DOC_FINAL_REPORT_SUPPLIER, $codes, 'Doc H missing');
        $this->assertContains(FinancialCodes::DOC_LOADING_ORDER, $codes, 'Doc C missing');
        $this->assertCount(3, $codes);
    }

    // ─── Weighbridge ──────────────────────────────────────────────────────────

    #[Test]
    public function weighbridge_equal_emits_only_bill_of_lading(): void
    {
        $this->generator->generateWeighbridgeDocs($this->order, isDeficit: false);

        $codes = $this->issuedCodes();
        $this->assertContains(FinancialCodes::DOC_BILL_OF_LADING, $codes, 'Doc I missing');
        $this->assertNotContains(FinancialCodes::DOC_MODIFIED_INVOICE_OWNER, $codes, 'Doc LO must not appear without deficit');
        $this->assertNotContains(FinancialCodes::DOC_MODIFIED_INVOICE_SUPPLIER, $codes, 'Doc LS must not appear without deficit');
        $this->assertCount(1, $codes);
    }

    #[Test]
    public function weighbridge_deficit_emits_three_docs(): void
    {
        $this->generator->generateWeighbridgeDocs($this->order, isDeficit: true);

        $codes = $this->issuedCodes();
        $this->assertContains(FinancialCodes::DOC_MODIFIED_INVOICE_OWNER, $codes, 'Doc LO missing');
        $this->assertContains(FinancialCodes::DOC_MODIFIED_INVOICE_SUPPLIER, $codes, 'Doc LS missing');
        $this->assertContains(FinancialCodes::DOC_BILL_OF_LADING, $codes, 'Doc I missing');
        $this->assertCount(3, $codes);
    }

    // ─── Cancellation ─────────────────────────────────────────────────────────

    #[Test]
    public function cancellation_without_refund_emits_only_cancel_report(): void
    {
        $this->generator->generateCancellationDocs($this->order, withRefundReceipt: false);

        $codes = $this->issuedCodes();
        $this->assertContains(FinancialCodes::DOC_CANCEL_REPORT, $codes, 'Doc j missing');
        $this->assertNotContains(FinancialCodes::DOC_REFUND_RECEIPT, $codes, 'Doc i must not appear without refund');
        $this->assertCount(1, $codes);
    }

    #[Test]
    public function cancellation_with_refund_emits_two_docs(): void
    {
        $this->generator->generateCancellationDocs($this->order, withRefundReceipt: true);

        $codes = $this->issuedCodes();
        $this->assertContains(FinancialCodes::DOC_REFUND_RECEIPT, $codes, 'Doc i missing');
        $this->assertContains(FinancialCodes::DOC_CANCEL_REPORT, $codes, 'Doc j missing');
        $this->assertCount(2, $codes);
    }

    // ─── Moadian (tax) invoices ───────────────────────────────────────────────

    #[Test]
    public function supplier_moadian_invoice_emits_doc_o(): void
    {
        $this->generator->generateSupplierMoadianInvoice($this->order);

        $this->assertContains(FinancialCodes::DOC_TAX_INVOICE_SUPPLIER, $this->issuedCodes());
        $this->assertCount(1, $this->issuedCodes());
    }

    #[Test]
    public function system_moadian_invoice_emits_doc_p(): void
    {
        $this->generator->generateSystemMoadianInvoice($this->order);

        $this->assertContains(FinancialCodes::DOC_TAX_INVOICE_BUYER, $this->issuedCodes());
        $this->assertCount(1, $this->issuedCodes());
    }

    #[Test]
    public function consultant_moadian_invoice_emits_doc_q(): void
    {
        $this->generator->generateEliteYARMoadianInvoice($this->order);

        $this->assertContains(FinancialCodes::DOC_TAX_INVOICE_ELITE_YAR, $this->issuedCodes());
        $this->assertCount(1, $this->issuedCodes());
    }

    // ─── View mapping contract ────────────────────────────────────────────────

    /**
     * هر کدِ سندی که FinancialCodes تعریف می‌کند باید به یک Blade *موجود* نگاشت
     * شود. سند T (survey_elite_yar) در typeMapِ کنترلر بود ولی بلیدش وجود نداشت
     * و سند S اصلاً case نداشت و خاموش به invoice_default سقوط می‌کرد.
     */
    #[Test]
    public function every_document_code_maps_to_an_existing_blade_view(): void
    {
        $missing = [];

        foreach ($this->allDocumentCodes() as $constant => $code) {
            $view = $this->viewNameFor($code);

            if (! View::exists($view)) {
                $missing[] = "{$constant} ({$code}) → {$view}";
            }
        }

        $this->assertSame([], $missing, 'Document codes without a Blade view: '.implode(', ', $missing));
    }

    #[Test]
    public function an_unknown_document_code_throws_instead_of_falling_back(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->viewNameFor('THIS_CODE_DOES_NOT_EXIST');
    }

    /** @return array<string, string> constant name => code */
    private function allDocumentCodes(): array
    {
        $codes = [];

        foreach ((new ReflectionClass(FinancialCodes::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'DOC_') && is_string($value)) {
                $codes[$name] = $value;
            }
        }

        return $codes;
    }

    private function viewNameFor(string $docType): string
    {
        $method = new ReflectionMethod(InvoiceGenerator::class, 'getViewName');
        $method->setAccessible(true);

        return $method->invoke($this->generator, $docType);
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    private function issuedCodes(): array
    {
        return Invoice::where('order_id', $this->order->id)
            ->pluck('document_code')
            ->all();
    }
}
