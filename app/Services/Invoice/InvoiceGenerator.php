<?php

namespace App\Services\Invoice;

use App\Constants\FinancialCodes;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\DocumentSequenceService;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrator: هماهنگ‌کننده تولید اسناد مالی.
 *
 * این کلاس خودش هیچ چیزی محاسبه نمی‌کند — کار را بین سه سرویس تقسیم می‌کند:
 *  - DocumentSequenceService : شماره‌گذاری یکتای سند
 *  - InvoiceDataBuilder      : آماده‌سازی داده برای Blade view
 *  - InvoicePdfRenderer      : رندر PDF و ذخیره روی disk
 */
class InvoiceGenerator
{
    public function __construct(
        protected DocumentSequenceService $sequenceService,
        protected InvoiceDataBuilder      $dataBuilder,
        protected InvoicePdfRenderer      $pdfRenderer,
    ) {}

    /**
     * متد اصلی تولید سند مالی.
     *
     * @param  string  $docType      نوع سند — یکی از ثابت‌های FinancialCodes
     * @param  string  $outputMode   'save' (ذخیره روی disk) | 'stream' | 'download' (خروجی PDF خام)
     * @return string|\Mpdf\Mpdf    در حالت save: مسیر فایل؛ غیر از آن: آبجکت PDF
     *
     * پیش‌نیاز: $order باید relations زیر را load کرده باشد:
     *   items.productVariant.product, placer.profile, address.city, address.province
     */
    public function generate(Order $order, string $docType, string $outputMode = 'save'): mixed
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
        $data     = $this->dataBuilder->build($order, $docType, $invoiceNumber);
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
        $firstItemCategory = $order->items->first()->productVariant->product->category_code ?? '';
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
            FinancialCodes::DOC_SUPPLEMENTARY_REPORT,
        ];

        $userId = in_array($docType, $supplierDocs)
            ? ($order->items->first()->supplier_user_id ?? $order->placer_user_id)
            : $order->placer_user_id;

        $amount = match ($docType) {
            FinancialCodes::DOC_RESERVE_RECEIPT,
            FinancialCodes::DOC_RESERVE_REPORT => $order->payment_to_reserve,
            default                             => $order->final_amount,
        };

        $invoice = Invoice::create([
            'order_id'       => $order->id,
            'user_id'        => $userId,
            'document_code'  => $docType,
            'invoice_number' => $invoiceNumber,
            'amount'         => $amount,
            'file_path'      => $filePath,
            'status'         => 'issued',
        ]);

        Log::info("سند مالی جدید: {$invoiceNumber} (کد: {$docType}) صادر و فایل آن ذخیره شد.");

        return $invoice;
    }

    private function getViewName(string $docType): string
    {
        return match ($docType) {
            FinancialCodes::DOC_PRE_INVOICE                => 'pdf.invoice_initial',
            FinancialCodes::DOC_RECEIPT_OWNER              => 'pdf.receipt_owner',
            FinancialCodes::DOC_REPORT_SUPPLIER            => 'pdf.report_supplier',
            FinancialCodes::DOC_LOADING_ORDER              => 'pdf.loading_order',
            FinancialCodes::DOC_RESERVE_RECEIPT            => 'pdf.reserve_receipt',
            FinancialCodes::DOC_RESERVE_REPORT             => 'pdf.reserve_report',
            FinancialCodes::DOC_FINAL_RECEIPT_OWNER        => 'pdf.final_receipt',
            FinancialCodes::DOC_FINAL_REPORT_SUPPLIER      => 'pdf.final_report',
            FinancialCodes::DOC_EXIT_PERMIT                => 'pdf.exit_permit',
            FinancialCodes::DOC_SUPPLEMENTARY_RECEIPT      => 'pdf.supplementary_receipt',
            FinancialCodes::DOC_SUPPLEMENTARY_REPORT       => 'pdf.supplementary_report',
            FinancialCodes::DOC_MODIFIED_INVOICE_OWNER     => 'pdf.modified_invoice_owner',
            FinancialCodes::DOC_MODIFIED_INVOICE_SUPPLIER  => 'pdf.modified_invoice_supplier',
            FinancialCodes::DOC_REFUND_REPORT_WEIGH        => 'pdf.refund_report',
            default                                        => 'pdf.invoice_default',
        };
    }
}
