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

        // Map URL-friendly slugs to document codes.
        // All document types are explicitly listed — no silent fallback to an
        // unrelated document. An unknown type returns 422 instead of sending
        // the wrong PDF to the client.
        $typeMap = [
            // Stage 0
            'initial' => FinancialCodes::DOC_PRE_INVOICE,
            // Stage 1 — direct purchase
            'receipt' => FinancialCodes::DOC_RECEIPT_OWNER,
            'supplier_report' => FinancialCodes::DOC_REPORT_SUPPLIER,
            'loading' => FinancialCodes::DOC_LOADING_ORDER,
            'consultant_report' => FinancialCodes::DOC_CONSULTANT_REPORT,
            // Stage 2 — reservation
            'reserve_receipt' => FinancialCodes::DOC_RESERVE_RECEIPT,
            'reserve_report' => FinancialCodes::DOC_RESERVE_REPORT,
            // Stage 3 — reservation completion
            'final' => FinancialCodes::DOC_FINAL_RECEIPT_OWNER,
            'final_supplier' => FinancialCodes::DOC_FINAL_REPORT_SUPPLIER,
            // Stage 4 — cancellation
            'refund' => FinancialCodes::DOC_REFUND_RECEIPT,
            'cancel_report' => FinancialCodes::DOC_CANCEL_REPORT,
            // Stage 5 — post-weighbridge (K=بارنامه/مجوز خروج، LO/LS=فاکتور اصلاح‌شده)
            'bill_of_lading' => FinancialCodes::DOC_BILL_OF_LADING,
            'modified_owner' => FinancialCodes::DOC_MODIFIED_INVOICE_OWNER,
            'modified_supplier' => FinancialCodes::DOC_MODIFIED_INVOICE_SUPPLIER,
            // Stage 6 — Moadian taxpayer system
            'tax' => FinancialCodes::DOC_TAX_INVOICE_BUYER,
            'tax_supplier' => FinancialCodes::DOC_TAX_INVOICE_SUPPLIER,
            'tax_consultant' => FinancialCodes::DOC_TAX_INVOICE_CONSULTANT,
            // Stage 7 — surveys
            'survey_consultant' => FinancialCodes::DOC_SURVEY_CONSULTANT,
        ];

        if (! array_key_exists($type, $typeMap)) {
            return error(__('orders.invalid_invoice_type'), [], 422);
        }

        $pdf = $generator->generate($order, $typeMap[$type], 'download');

        return $pdf->download("invoice-{$order->order_number}-{$type}.pdf");
    }
}
