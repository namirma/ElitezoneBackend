<?php

namespace App\Services\Invoice;

use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mccarlosen\LaravelMpdf\Facades\LaravelMpdf as PDF;

/**
 * مسئولیت: رندر کردن PDF از یک Blade view و ذخیره یا ارائه آن.
 *
 * این کلاس هیچ‌چیزی درباره محتوا، شماره‌گذاری، یا دیتابیس نمی‌داند —
 * فقط یک view و داده می‌گیرد و PDF تولید می‌کند.
 */
class InvoicePdfRenderer
{
    /**
     * PDF را رندر کرده و روی disk ذخیره می‌کند.
     *
     * فایل روی دیسک خصوصی `financial` ذخیره می‌شود (نه public) — این آرشیوِ
     * اسناد مالی است و نباید از طریق URL عمومی قابل‌دسترس باشد. سرویسِ دانلودِ
     * فاکتور (OrderInvoiceController) PDF را در لحظه بازتولید می‌کند و به این
     * فایلِ ذخیره‌شده وابسته نیست.
     *
     * @return string مسیر فایل ذخیره‌شده (نسبت به دیسک financial)
     */
    public function renderAndSave(string $viewName, array $data, string $docType, string $invoiceNumber): string
    {
        $pdf = $this->buildPdf($viewName, $data, $docType, $invoiceNumber);
        $filename = "invoices/{$invoiceNumber}_".time().'.pdf';

        Storage::disk('financial')->put($filename, $pdf->output());

        return $filename;
    }

    /**
     * PDF را رندر کرده و آبجکت آن را برمی‌گرداند (برای stream/download مستقیم).
     *
     * @return \Mpdf\Mpdf|\Mccarlosen\LaravelMpdf\LaravelMpdf
     */
    public function render(string $viewName, array $data, string $docType, string $invoiceNumber)
    {
        return $this->buildPdf($viewName, $data, $docType, $invoiceNumber);
    }

    /**
     * آبجکت PDF را می‌سازد — منطق مشترک renderAndSave و render.
     */
    private function buildPdf(string $viewName, array $data, string $docType, string $invoiceNumber)
    {
        $this->assertFontsInstalled();

        // همه اسناد در قالب Landscape A4 صادر می‌شوند —
        // گرید ۱۱-ستونه برای عرض landscape طراحی شده و در portrait به‌هم می‌ریزد.
        return PDF::loadView($viewName, $data, [], [
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'orientation' => 'L',
            'margin_left' => 8,
            'margin_right' => 8,
            'margin_top' => 8,
            'margin_bottom' => 8,
            'default_font' => 'yekanbakh',
            'title' => "Document_{$invoiceNumber}",
        ]);
    }

    /**
     * پیش‌بررسی فونت‌های فارسی، پیش از اینکه mPDF شکست بخورد.
     *
     * چرا لازم است: فایل‌های `storage/fonts/YekanBakh-*.ttf` در `.gitignore`
     * هستند، پس روی هر clone تازه **وجود ندارند**. بدون این بررسی، mPDF یک
     * `\Mpdf\MpdfException` با متن انگلیسی و مسیرِ فایل‌سیستم پرتاب می‌کرد که
     * مستقیم در پاسخ API می‌نشست — هم نامفهوم برای کاربر، هم افشای مسیر داخلی
     * (نقض قاعده‌ی «پیام خامِ سرویس/کتابخانه را برنگردان»).
     *
     * و مهم‌تر: تولید هر سند داخل `DB::transaction` پرداخت اتفاق می‌افتد، پس
     * فونتِ نبوده **کل پرداخت را rollback می‌کند**. این خطا باید در لاگ صریح و
     * قابل‌اقدام باشد تا کسی ساعت‌ها دنبال باگِ پرداخت نگردد.
     */
    private function assertFontsInstalled(): void
    {
        $dir = (string) config('pdf.custom_font_dir');
        $required = ['YekanBakh-Regular.ttf', 'YekanBakh-Bold.ttf'];

        $missing = array_values(array_filter(
            $required,
            fn (string $file) => ! is_file(rtrim($dir, '\\/').DIRECTORY_SEPARATOR.$file)
        ));

        if ($missing === []) {
            return;
        }

        Log::channel('financial')->critical('Invoice PDF generation is unavailable: Persian fonts are missing', [
            'font_dir' => $dir,
            'missing' => $missing,
            'hint' => 'Copy the TTF files into storage/fonts/ — they are gitignored, so a fresh clone never has them.',
        ]);

        throw new BusinessException(__('orders.invoice_fonts_missing'), 503);
    }
}
