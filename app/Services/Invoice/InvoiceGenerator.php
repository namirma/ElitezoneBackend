<?php

namespace App\Services\Invoice;

use App\Actions\Cart\BuildCartPreInvoiceAction;
use App\Constants\FinancialCodes;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\DocumentSequenceService;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Mccarlosen\LaravelMpdf\LaravelMpdf;
use Mpdf\Mpdf;

/**
 * Orchestrator: هماهنگ‌کننده تولید اسناد مالی.
 *
 * این کلاس خودش هیچ چیزی محاسبه نمی‌کند — کار را بین سه سرویس تقسیم می‌کند:
 *  - DocumentSequenceService : شماره‌گذاری یکتای سند
 *  - InvoiceDataBuilder      : آماده‌سازی داده برای Blade view
 *  - InvoicePdfRenderer      : رندر PDF و ذخیره روی disk
 *
 * Public API — scenario methods (save mode, void):
 *   generateDirectPurchaseDocs()        — Docs A, B, C (+ D if eliteYAR)
 *   generateReservationDocs()           — Docs E, F
 *   generateReservationCompletionDocs() — Docs G, H, C
 *   generateWeighbridgeDocs()           — Doc I (+ LO/LS if deficit)
 *   generateCancellationDocs()          — Doc j (+ i if refund happened)
 *   generateSupplierMoadianInvoice()    — Doc O
 *   generateSystemMoadianInvoice()      — Doc P
 *   generateEliteYARMoadianInvoice()  — Doc Q
 *   download()                          — any single doc as a streamed PDF
 */
class InvoiceGenerator
{
    public function __construct(
        protected DocumentSequenceService $sequenceService,
        protected InvoiceDataBuilder $dataBuilder,
        protected InvoicePdfRenderer $pdfRenderer,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Scenario entry-points (callers express intent; routing logic stays here)
    // ─────────────────────────────────────────────────────────────────────────

    /** Docs A + B + C (direct purchase). Adds Doc D when $withEliteYAR. */
    public function generateDirectPurchaseDocs(Order $order, bool $withEliteYAR = false): void
    {
        $this->generate($order, FinancialCodes::DOC_RECEIPT_OWNER);
        $this->generate($order, FinancialCodes::DOC_REPORT_SUPPLIER);
        $this->generate($order, FinancialCodes::DOC_LOADING_ORDER);
        if ($withEliteYAR) {
            $this->generate($order, FinancialCodes::DOC_ELITE_YAR_REPORT);
        }
    }

    /** Docs E + F (reservation start). */
    public function generateReservationDocs(Order $order): void
    {
        $this->generate($order, FinancialCodes::DOC_RESERVE_RECEIPT);
        $this->generate($order, FinancialCodes::DOC_RESERVE_REPORT);
    }

    /** Docs G + H + C (reservation completion). */
    public function generateReservationCompletionDocs(Order $order): void
    {
        $this->generate($order, FinancialCodes::DOC_FINAL_RECEIPT_OWNER);
        $this->generate($order, FinancialCodes::DOC_FINAL_REPORT_SUPPLIER);
        $this->generate($order, FinancialCodes::DOC_LOADING_ORDER);
    }

    /** Doc I (bill of lading). Prepends Docs LO + LS when $isDeficit. */
    public function generateWeighbridgeDocs(Order $order, bool $isDeficit): void
    {
        if ($isDeficit) {
            $this->generate($order, FinancialCodes::DOC_MODIFIED_INVOICE_OWNER);
            $this->generate($order, FinancialCodes::DOC_MODIFIED_INVOICE_SUPPLIER);
        }
        $this->generate($order, FinancialCodes::DOC_BILL_OF_LADING);
    }

    /** Doc j (cancellation report). Prepends Doc i when $withRefundReceipt. */
    public function generateCancellationDocs(Order $order, bool $withRefundReceipt): void
    {
        if ($withRefundReceipt) {
            $this->generate($order, FinancialCodes::DOC_REFUND_RECEIPT);
        }
        $this->generate($order, FinancialCodes::DOC_CANCEL_REPORT);
    }

    /** Doc O — supplier Moadian (tax) invoice. */
    public function generateSupplierMoadianInvoice(Order $order): void
    {
        $this->generate($order, FinancialCodes::DOC_TAX_INVOICE_SUPPLIER);
    }

    /** Doc P — system (Saze) Moadian invoice. */
    public function generateSystemMoadianInvoice(Order $order): void
    {
        $this->generate($order, FinancialCodes::DOC_TAX_INVOICE_BUYER);
    }

    /** Doc Q — eliteYAR Moadian invoice. */
    public function generateEliteYARMoadianInvoice(Order $order): void
    {
        $this->generate($order, FinancialCodes::DOC_TAX_INVOICE_ELITE_YAR);
    }

    /** Returns the raw PDF object for streaming/download to the client. */
    public function download(Order $order, string $docType): mixed
    {
        return $this->generate($order, $docType, 'download');
    }

    /**
     * Doc 0 — پیش‌فاکتورِ یک سبدِ **هنوز ثبت‌نشده**.
     *
     * تفاوتش با `download($order, DOC_PRE_INVOICE)` در سه چیز است و هر سه عمدی‌اند:
     *
     * ۱. **هیچ رکورد `Invoice` ساخته نمی‌شود.** هنوز سفارشی وجود ندارد که سند به
     *    آن بچسبد؛ ساختنِ رکورد یعنی یک فاکتورِ یتیم با `order_id` نامعتبر.
     * ۲. **هیچ شماره‌ی دنباله‌ای مصرف نمی‌شود.** `DocumentSequenceService` شماره را
     *    از روی آخرین ردیفِ ذخیره‌شده می‌سازد؛ چون اینجا چیزی ذخیره نمی‌شود، شماره
     *    رزرو هم نمی‌شود و همان عدد بعداً به یک سندِ واقعی می‌رسید. یعنی مشتری یک
     *    PDF با شماره‌ای در دست داشت که بعداً روی سندِ بی‌ربطِ دیگری می‌نشست. پس
     *    شماره‌ی پیش‌نمایش از بیرون داده می‌شود و باید **آشکارا موقت** باشد.
     * ۳. **`loadMissing` اجرا نمی‌شود.** سفارشِ ورودی transient است (`id = null`)،
     *    و هر eager-load روی آن یک کوئریِ `where order_id is null` می‌زند. تمام
     *    relationها باید از قبل با `setRelation()` روی مدل نشسته باشند.
     *
     * @param  Order  $transientOrder  سفارشِ درون‌حافظه‌ای (ذخیره‌نشده) ساخته‌ی سبد
     * @param  string  $previewNumber  شناسه‌ی آشکارا موقتِ پیش‌نمایش
     * @return Mpdf|LaravelMpdf
     *
     * @see BuildCartPreInvoiceAction
     */
    public function previewPreInvoice(Order $transientOrder, string $previewNumber): mixed
    {
        $docType = FinancialCodes::DOC_PRE_INVOICE;

        $data = $this->dataBuilder->build($transientOrder, $docType, $previewNumber);

        return $this->pdfRenderer->render($this->getViewName($docType), $data, $docType, $previewNumber);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal implementation (routing + PDF pipeline)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  string  $outputMode  'save' (ذخیره روی disk) | 'stream' | 'download' (خروجی PDF خام)
     * @return string|Mpdf در حالت save: مسیر فایل؛ غیر از آن: آبجکت PDF
     *
     * پیش‌نیاز: $order باید relations زیر را load کرده باشد:
     *   items.productVariant.product, placer.profile, address.city, address.province
     */
    private function generate(Order $order, string $docType, string $outputMode = 'save'): mixed
    {
        // 1. بارگذاری داده‌های ضروری که caller ممکن است فراموش کرده باشد
        $order->loadMissing([
            'items.productVariant.product',
            'placer.profile',
            'address.city',
            'address.province',
        ]);

        // 2. شماره یکتای سند
        $invoiceNumber = $this->generateDocumentNumber($order, $docType);

        // 3. آماده‌سازی داده
        $data = $this->dataBuilder->build($order, $docType, $invoiceNumber);
        $viewName = $this->getViewName($docType);

        // 4. تولید PDF و (در صورت لزوم) ذخیره
        if ($outputMode === 'save') {
            $filename = $this->pdfRenderer->renderAndSave($viewName, $data, $docType, $invoiceNumber);
            $this->createInvoiceRecord($order, $docType, $invoiceNumber, $filename);

            return $filename;
        }

        return $this->pdfRenderer->render($viewName, $data, $docType, $invoiceNumber);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers (ترتیب سند + ثبت DB)
    // ─────────────────────────────────────────────────────────────────────────

    private function generateDocumentNumber(Order $order, string $docType): string
    {
        $firstItemCategory = $order->items->first()?->productVariant?->product?->category_code ?? '';
        $context = (strtoupper($firstItemCategory) === 'BRICK')
            ? DocumentSequenceService::CTX_BRICK
            : DocumentSequenceService::CTX_REBAR;

        $type = ($docType === FinancialCodes::DOC_PRE_INVOICE)
            ? DocumentSequenceService::TYPE_QUOTE
            : DocumentSequenceService::TYPE_INVOICE;

        return $this->sequenceService->generate(
            $type,
            $context,
            Invoice::class,
            'invoice_number'
        );
    }

    private function createInvoiceRecord(Order $order, string $docType, string $invoiceNumber, string $filePath): Invoice
    {
        $supplierDocs = [
            FinancialCodes::DOC_REPORT_SUPPLIER,
            FinancialCodes::DOC_RESERVE_REPORT,
            FinancialCodes::DOC_FINAL_REPORT_SUPPLIER,
            FinancialCodes::DOC_MODIFIED_INVOICE_SUPPLIER,
            FinancialCodes::DOC_CANCEL_REPORT,         // j — گزارش لغو برای سوپلایر
            FinancialCodes::DOC_TAX_INVOICE_SUPPLIER,  // O — فاکتور مودیان سوپلایر
        ];

        // اسنادِ خریدار به نامِ **پرداخت‌کننده** ثبت می‌شوند و نه ثبت‌کننده (فاز ۶).
        // در مسیرِ «الیت‌یار برای کارفرما»، خریدارِ واقعی کارفرماست؛ صدور سند P
        // (فاکتور مودیانِ خریدار) به نامِ الیت‌یار یک خطای حسابداری واقعی بود.
        $userId = in_array($docType, $supplierDocs)
            ? ($order->items->first()->supplier_user_id ?? $order->payerUserId())
            : $order->payerUserId();

        // مبلغِ ثبت‌شده‌ی هر سند باید «موضوعِ واقعیِ» همان سند باشد، نه همیشه final_amount.
        // لِگ‌های کیف‌پول مستقل و درست‌اند؛ این فقط فیلدِ گزارشیِ invoices.amount را دقیق می‌کند.
        // هر سند «موضوعِ واقعیِ» خودش را ثبت می‌کند؛ اگر آن فیلد هنوز مقدار ندارد (مثلاً
        // سندی که پیش از مقداردهیِ آن فیلد تولید شود)، به final_amount برمی‌گردیم تا
        // invoices.amount هرگز NULL نشود (ستون NOT NULL است). لِگ‌های کیف‌پول مستقل‌اند.
        $amount = match ($docType) {
            FinancialCodes::DOC_RESERVE_RECEIPT,
            FinancialCodes::DOC_RESERVE_REPORT => $order->{Order::PAYMENT_TO_RESERVE} ?? $order->final_amount,
            // اسنادِ اصلاحیِ باسکول: مبلغِ اصلاح‌شده‌ی هر طرف (POOM خریدار / PTSM سوپلایر).
            FinancialCodes::DOC_MODIFIED_INVOICE_OWNER => $order->{Order::PAYMENT_OF_OWNER} ?? $order->final_amount,
            FinancialCodes::DOC_MODIFIED_INVOICE_SUPPLIER => $order->{Order::PAYMENT_TO_SUPPLIER} ?? $order->final_amount,
            // فاکتورهای مودیان: مبلغی که هر فاکتور درباره‌ی آن است.
            FinancialCodes::DOC_TAX_INVOICE_SUPPLIER => $order->{Order::PAYMENT_TO_SUPPLIER} ?? $order->final_amount, // O
            FinancialCodes::DOC_TAX_INVOICE_BUYER => $order->{Order::PAYMENT_OF_OWNER} ?? $order->final_amount,        // P
            FinancialCodes::DOC_TAX_INVOICE_ELITE_YAR => $order->{Order::PAYMENT_TO_ELITE_YAR} ?? $order->final_amount, // Q
            default => $order->final_amount,
        };

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'user_id' => $userId,
            'document_code' => $docType,
            'invoice_number' => $invoiceNumber,
            'amount' => $amount,
            'file_path' => $filePath,
            'status' => Invoice::STATUS_ISSUED,
        ]);

        Log::channel('orders')->info("سند مالی جدید: {$invoiceNumber} (کد: {$docType}) صادر و فایل آن ذخیره شد.");

        return $invoice;
    }

    private function getViewName(string $docType): string
    {
        return match ($docType) {
            // ── Stage 0: pre-payment ───────────────────────────────────────
            FinancialCodes::DOC_PRE_INVOICE => 'pdf.invoice_initial',
            // ── Stage 1: direct purchase ───────────────────────────────────
            FinancialCodes::DOC_RECEIPT_OWNER => 'pdf.receipt_owner',
            FinancialCodes::DOC_REPORT_SUPPLIER => 'pdf.report_supplier',
            FinancialCodes::DOC_LOADING_ORDER => 'pdf.loading_order',
            FinancialCodes::DOC_ELITE_YAR_REPORT => 'pdf.elite_yar_report',
            // ── Stage 2: reservation ───────────────────────────────────────
            FinancialCodes::DOC_RESERVE_RECEIPT => 'pdf.reserve_receipt',
            FinancialCodes::DOC_RESERVE_REPORT => 'pdf.reserve_report',
            // ── Stage 3: reservation completion ───────────────────────────
            FinancialCodes::DOC_FINAL_RECEIPT_OWNER => 'pdf.final_receipt',
            FinancialCodes::DOC_FINAL_REPORT_SUPPLIER => 'pdf.final_report',
            // ── Stage 4: cancellation ──────────────────────────────────────
            FinancialCodes::DOC_REFUND_RECEIPT => 'pdf.refund_receipt',
            FinancialCodes::DOC_CANCEL_REPORT => 'pdf.cancel_report',
            // ── Stage 5: post-weighbridge ──────────────────────────────────
            // K — بارنامه / مجوز خروج (طبق سند طراحی: K = صدور بارنامه توسط فولاد کویر)
            FinancialCodes::DOC_BILL_OF_LADING => 'pdf.exit_permit',
            FinancialCodes::DOC_MODIFIED_INVOICE_OWNER => 'pdf.modified_invoice_owner',
            FinancialCodes::DOC_MODIFIED_INVOICE_SUPPLIER => 'pdf.modified_invoice_supplier',
            // ── Stage 6: Moadian taxpayer system ──────────────────────────
            FinancialCodes::DOC_TAX_INVOICE_SUPPLIER => 'pdf.tax_invoice_supplier',
            FinancialCodes::DOC_TAX_INVOICE_BUYER => 'pdf.tax_invoice_buyer',
            FinancialCodes::DOC_TAX_INVOICE_ELITE_YAR => 'pdf.tax_invoice_elite_yar',
            // ── Stage 7: surveys ───────────────────────────────────────────
            FinancialCodes::DOC_SURVEY => 'pdf.survey_buyer',
            FinancialCodes::DOC_SURVEY_ELITE_YAR => 'pdf.survey_elite_yar',
            // بدونِ default — سقوط به invoice_default یعنی تحویلِ خاموشِ «سندِ
            // اشتباه» به کاربر. کدِ ناشناخته باید سر و صدا کند، نه یک فاکتور
            // بی‌ربط تولید کند.
            default => throw new InvalidArgumentException("Unknown invoice document type [{$docType}]."),
        };
    }
}
