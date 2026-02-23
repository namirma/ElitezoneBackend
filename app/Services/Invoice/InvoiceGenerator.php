<?php

namespace App\Services\Invoice;

use App\Constants\FinancialCodes;
use App\Models\Invoice; // مدل فاکتور/اسناد مالی شما در دیتابیس
use App\Models\Order;
use App\Services\DocumentSequenceService;
use App\Traits\ConvertNumberToWord;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Mccarlosen\LaravelMpdf\Facades\LaravelMpdf as PDF;

class InvoiceGenerator
{
    use ConvertNumberToWord;

    /**
     * اطلاعات ثابت فروشنده (گروه نرم‌افزاری سازه)
     */
    protected const SELLER_INFO = [
        'name'          => 'شرکت نرم‌افزاری سازه اسپادانا',
        'economic_code' => '411347688653',
        'reg_id'        => '31106',
        'national_id'   => '10260516722',
        'address'       => 'اصفهان، پارک علم و فناوری، ساختمان شهرک علمی تحقیقاتی، واحد 114',
        'phone'         => '03134005000',
        'postal_code'   => '8158184500',
    ];

    public function __construct(
        protected DocumentSequenceService $sequenceService
    ) {}

    /**
     * متد اصلی برای تولید سند مالی و فایل PDF
     *
     * @param Order $order
     * @param string $docType نوع سند (بر اساس FinancialCodes)
     * @param string $outputMode 'stream', 'download', 'save'
     * @return mixed مسیر فایل، آبجکت PDF یا مدل Invoice
     */
    public function generate(Order $order, string $docType, string $outputMode = 'save')
    {
        // 1. بارگذاری داده‌های مورد نیاز
        $order->loadMissing(['items.productVariant.product', 'placer.profile', 'address.city', 'address.province']);

        // 2. تولید شماره سند (Sequence) استاندارد
        $invoiceNumber = $this->generateDocumentNumber($order, $docType);

        // 3. آماده‌سازی داده‌ها برای ویو (ارسال شماره سند به PDF)
        $data = $this->prepareData($order, $docType, $invoiceNumber);

        // 4. انتخاب فایل ویو (Blade) بر اساس نوع سند
        $viewName = $this->getViewName($docType);

        // 5. تولید فایل PDF
        $pdf = PDF::loadView($viewName, $data, [], [
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'orientation'   => 'P',
            'margin_left'   => 5,
            'margin_right'  => 5,
            'margin_top'    => 5,
            'margin_bottom' => 5,
            'default_font'  => 'yekan',
            'title'         => "Document_{$invoiceNumber}",
        ]);

        // 6. خروجی و ثبت در دیتابیس
        if ($outputMode === 'save') {
            $filename = "invoices/{$invoiceNumber}_" . time() . ".pdf";
            Storage::disk('public')->put($filename, $pdf->output());

            // 7. ثبت رسمی سند در دیتابیس با شماره یکتا
            $invoice = $this->createInvoiceRecord($order, $docType, $invoiceNumber, $filename);

            // می‌توانید مستقیماً مسیر فایل را برگردانید یا آبجکت Invoice را برگردانید (بسته به نیاز کنترلرهایتان)
            return $filename;
        }

        return $pdf; // برای دانلود یا نمایش مستقیم (بدون ذخیره)
    }

    /**
     * تولید شماره استاندارد (مثل EZ-INV-RB-140502-70300100)
     */
    protected function generateDocumentNumber(Order $order, string $docType): string
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

    /**
     * ثبت رکورد سند در دیتابیس (ردیابی مالی)
     */
    protected function createInvoiceRecord(Order $order, string $docType, string $invoiceNumber, string $filePath): Invoice
    {
        // تشخیص اینکه این سند متعلق به خریدار است یا تامین‌کننده
        $supplierDocs = [FinancialCodes::DOC_REPORT_SUPPLIER, FinancialCodes::DOC_RESERVE_REPORT, FinancialCodes::DOC_FINAL_REPORT_SUPPLIER, FinancialCodes::DOC_MODIFIED_INVOICE_SUPPLIER, FinancialCodes::DOC_SUPPLEMENTARY_REPORT];

        $userId = in_array($docType, $supplierDocs)
            ? ($order->items->first()->supplier_user_id ?? $order->placer_user_id)
            : $order->placer_user_id;

        $amount = match($docType) {
            FinancialCodes::DOC_RESERVE_RECEIPT, FinancialCodes::DOC_RESERVE_REPORT => $order->payment_to_reserve,
            default => $order->final_amount
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

    /**
     * آماده‌سازی داده‌های مشترک و اختصاصی برای ارسال به ویو
     */
    protected function prepareData(Order $order, string $docType, string $invoiceNumber): array
    {
        $buyerProfile = $order->placer->profile;

        $amountToConvert = match($docType) {
            FinancialCodes::DOC_RESERVE_RECEIPT, FinancialCodes::DOC_RESERVE_REPORT => $order->payment_to_reserve,
            default => $order->final_amount
        };

        return [
            'invoice_number'=> $invoiceNumber, // پاس دادن شماره یکتا به قالب PDF
            'order'         => $order,
            'seller'        => self::SELLER_INFO,
            'buyer'         => [
                'name'          => ($buyerProfile->first_name ?? '') . ' ' . ($buyerProfile->last_name ?? ''),
                'economic_code' => $buyerProfile->economic_code ?? '-',
                'national_id'   => $order->placer->national_code ?? '-',
                'phone'         => $order->placer->mobile,
                'postal_code'   => $order->address->postal_code ?? '-',
                'address'       => $order->address->postal_address ?? $order->address->address ?? '-',
            ],
            'doc_type'      => $docType,
            'date_shamsi'   => verta()->format('Y/m/d'),
            'amount_words'  => $this->numberToWords($amountToConvert),
        ];
    }

    /**
     * انتخاب فایل Blade بر اساس کد سند جدید
     */
    protected function getViewName(string $docType): string
    {
        return match ($docType) {
            // --- مرحله ۰ ---
            FinancialCodes::DOC_PRE_INVOICE           => 'pdf.invoice_initial',      // 0

            // --- خرید مستقیم ---
            FinancialCodes::DOC_RECEIPT_OWNER         => 'pdf.receipt_owner',        // A
            FinancialCodes::DOC_REPORT_SUPPLIER       => 'pdf.report_supplier',      // B
            FinancialCodes::DOC_LOADING_ORDER         => 'pdf.loading_order',        // C

            // --- رزرو ---
            FinancialCodes::DOC_RESERVE_RECEIPT       => 'pdf.reserve_receipt',      // E
            FinancialCodes::DOC_RESERVE_REPORT        => 'pdf.reserve_report',       // F

            // --- تکمیل وجه رزرو ---
            FinancialCodes::DOC_FINAL_RECEIPT_OWNER   => 'pdf.final_receipt',        // G
            FinancialCodes::DOC_FINAL_REPORT_SUPPLIER => 'pdf.final_report',         // H

            // --- باسکول (کدهای جدید) ---
            FinancialCodes::DOC_EXIT_PERMIT               => 'pdf.exit_permit',                 // I
            FinancialCodes::DOC_SUPPLEMENTARY_RECEIPT     => 'pdf.supplementary_receipt',       // J
            FinancialCodes::DOC_SUPPLEMENTARY_REPORT      => 'pdf.supplementary_report',        // K
            FinancialCodes::DOC_MODIFIED_INVOICE_OWNER    => 'pdf.modified_invoice_owner',      // LO
            FinancialCodes::DOC_MODIFIED_INVOICE_SUPPLIER => 'pdf.modified_invoice_supplier',   // LS
            FinancialCodes::DOC_REFUND_REPORT_WEIGH       => 'pdf.refund_report',               // M

            // --- پیش‌فرض ---
            default => 'pdf.invoice_default', // قالب عمومی
        };
    }
}
