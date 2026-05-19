<?php

namespace App\Http\Controllers\Api\User;

use App\Constants\FinancialCodes;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Invoice\InvoiceGenerator;

class OrderInvoiceController extends Controller
{
    public function download(Order $order, string $type, InvoiceGenerator $generator)
    {
        $this->authorize('view', $order);

        $financialCode = match ($type) {
            'initial' => FinancialCodes::DOC_PRE_INVOICE,
            default => FinancialCodes::DOC_PRE_INVOICE,
        };
        $pdf = $generator->generate($order, $financialCode, 'download');

        return $pdf->download("invoice-{$order->order_number}.pdf");
    }
}
