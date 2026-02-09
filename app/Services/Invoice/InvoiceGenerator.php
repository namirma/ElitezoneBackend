<?php

namespace App\Services\Invoice;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Traits\ConvertNumberToWord;
use Illuminate\Support\Facades\Storage;
use Mccarlosen\LaravelMpdf\Facades\LaravelMpdf as PDF;

class InvoiceGenerator
{
    use ConvertNumberToWord;

    /**
     * اطلاعات ثابت فروشنده (می‌تواند به کانفیگ منتقل شود)
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

    /**
     * متد اصلی برای تولید سند
     *
     * @param Order $order
     * @param string $docType نوع سند (مثلا 'A', 'B', 'initial', ...)
     * @param string $outputMode 'stream', 'download', 'save' (پیش‌فرض ذخیره در فایل)
     * @return mixed مسیر فایل یا آبجکت PDF
     */
    public function generate(Order $order, string $docType, string $outputMode = 'save')
    {
        // 1. بارگذاری داده‌های مورد نیاز
        $order->load(['items.productVariant', 'placer.profile', 'address.city', 'address.province']);

        // 2. آماده‌سازی داده‌ها برای ویو
        $data = $this->prepareData($order, $docType);

        // 3. انتخاب فایل ویو (Blade) بر اساس نوع سند
        $viewName = $this->getViewName($docType);

        // 4. تنظیمات PDF
        $pdf = PDF::loadView($viewName, $data, [], [
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'orientation'   => 'P',
            'margin_left'   => 5,
            'margin_right'  => 5,
            'margin_top'    => 5,
            'margin_bottom' => 5,
            'default_font'  => 'yekan',
            'title'         => "Order_{$order->order_number}_{$docType}",
        ]);

        // 5. خروجی (ذخیره یا نمایش)
        if ($outputMode === 'save') {
            $filename = "invoices/order_{$order->id}_{$docType}_" . time() . ".pdf";
            Storage::disk('public')->put($filename, $pdf->output());
            return $filename; // مسیر فایل ذخیره شده را برمی‌گرداند
        }

        return $pdf; // برای دانلود یا نمایش مستقیم
    }

    /**
     * آماده‌سازی داده‌های مشترک و اختصاصی برای ارسال به ویو
     */
    protected function prepareData(Order $order, string $docType): array
    {
        $buyerProfile = $order->placer->profile;

        // محاسبه مبلغ به حروف (بسته به نوع سند ممکن است مبلغ متفاوت باشد)
        $amountToConvert = match($docType) {
            FinancialCodes::DOC_RESERVE_RECEIPT => $order->payment_to_reserve,
            default => $order->final_amount
        };

        return [
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
     * انتخاب فایل Blade بر اساس کد سند
     */
    protected function getViewName(string $docType): string
    {
        return match ($docType) {
            // --- خرید مستقیم ---
            FinancialCodes::DOC_RECEIPT_OWNER       => 'pdf.receipt_owner',      // A
            FinancialCodes::DOC_REPORT_SUPPLIER     => 'pdf.report_supplier',    // B
            FinancialCodes::DOC_LOADING_ORDER       => 'pdf.loading_order',      // C

            // --- رزرو ---
            FinancialCodes::DOC_RESERVE_RECEIPT     => 'pdf.reserve_receipt',    // E
            FinancialCodes::DOC_RESERVE_REPORT      => 'pdf.reserve_report',     // F

            // --- تکمیل وجه رزرو ---
            FinancialCodes::DOC_FINAL_RECEIPT_OWNER => 'pdf.final_receipt',      // G
            FinancialCodes::DOC_FINAL_REPORT_SUPPLIER => 'pdf.final_report',     // H

            // --- باسکول ---
            FinancialCodes::DOC_EXIT_PERMIT         => 'pdf.exit_permit',        // I
            FinancialCodes::DOC_SUPPLEMENTARY_RECEIPT => 'pdf.supplementary_receipt',     // J
            FinancialCodes::DOC_SUPPLEMENTARY_REPORT  => 'pdf.supplementary_report',      // K
            FinancialCodes::DOC_REFUND_RECEIPT_WEIGH  => 'pdf.refund_receipt',   // L
            FinancialCodes::DOC_REFUND_REPORT_WEIGH   => 'pdf.refund_report',    // M

            // --- پیش‌فرض و قدیمی ---
            'initial' => 'pdf.invoice_initial', // پیش‌فاکتور
            'tax'     => 'pdf.invoice_tax',     // فاکتور رسمی
            default   => 'pdf.invoice_default', // قالب عمومی
        };
    }
}
