<?php

namespace App\Services\Invoice;

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
}
