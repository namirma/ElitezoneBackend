<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Http\Response;

class OrderInvoiceController extends Controller
{
    public function download(Order $order, string $type, InvoiceGenerator $generator)
    {
        $this->authorize('view', $order);

        $pdf = $generator->generate($order, $type);

        return $pdf->download("invoice-{$order->order_number}.pdf");
    }
}
