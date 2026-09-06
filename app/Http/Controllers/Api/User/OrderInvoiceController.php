<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Invoice\InvoiceGenerator;
use App\Support\OrderDocumentCatalog;
use Illuminate\Support\Facades\Auth;

class OrderInvoiceController extends Controller
{
    /**
     * دانلود سندِ سفارش.
     *
     * نقشهٔ «اسلاگ → کد» و نقشهٔ «سند → مخاطب» هر دو در `OrderDocumentCatalog`
     * زندگی می‌کنند، چون روتِ همین متد هم به همان فهرست نیاز دارد. وقتی این دانش
     * اینجا کپی شده بود، whitelist روت از نقشهٔ مخاطبان واگرا شد و برای سوپلایر و
     * الیت‌یار هیچ سندی باقی نماند.
     */
    public function download(Order $order, string $type, InvoiceGenerator $generator)
    {
        $this->authorize('view', $order);

        $code = OrderDocumentCatalog::codeFor($type);

        if ($code === null) {
            return error(__('orders.invalid_invoice_type'), [], 422);
        }

        // مجوزِ به‌ازای سند: فقط طرفی که سند به او مربوط است (یا ادمین) — نه هر کسی
        // که از گاردِ عمومیِ `view` رد شده.
        if (! $this->mayAccessDocument($order, $type)) {
            return error(__('global.forbidden'), [], 403);
        }

        $pdf = $generator->download($order, $code);

        return $pdf->download("invoice-{$order->order_number}-{$type}.pdf");
    }

    /**
     * آیا کاربرِ احرازشده در فهرستِ مخاطبانِ این سند برای این سفارش هست؟
     * ادمین به همه چیز دسترسی دارد؛ سندِ نگاشته‌نشده برای همه ممنوع است.
     */
    private function mayAccessDocument(Order $order, string $type): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        if ($user->hasRole('admin')) {
            return true;
        }

        return array_intersect(
            OrderDocumentCatalog::audiencesFor($type),
            $order->partyRolesFor((int) $user->id)
        ) !== [];
    }
}
