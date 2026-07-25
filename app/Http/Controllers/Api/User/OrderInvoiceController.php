<?php

namespace App\Http\Controllers\Api\User;

use App\Constants\FinancialCodes;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Support\Facades\Auth;

class OrderInvoiceController extends Controller
{
    /**
     * Which party each document belongs to. A document is only downloadable by the
     * party (or parties) it concerns — plus admins. Without this, any order party who
     * passes the generic `view` check could pull another party's invoices/receipts
     * (e.g. a supplier downloading the buyer's tax invoice).
     *
     * Audiences: 'buyer' (placer) | 'supplier' (item supplier) | 'elite_yar' (beneficiary).
     */
    private const DOCUMENT_AUDIENCES = [
        'initial' => ['buyer'],
        'receipt' => ['buyer'],
        'reserve_receipt' => ['buyer'],
        'final' => ['buyer'],
        'refund' => ['buyer'],
        'modified_owner' => ['buyer'],
        'tax' => ['buyer'],
        'bill_of_lading' => ['buyer', 'supplier'],
        'loading' => ['buyer', 'supplier'],
        'supplier_report' => ['supplier'],
        'reserve_report' => ['supplier'],
        'final_supplier' => ['supplier'],
        'cancel_report' => ['supplier'],
        'modified_supplier' => ['supplier'],
        'tax_supplier' => ['supplier'],
        'elite_yar_report' => ['elite_yar'],
        'tax_elite_yar' => ['elite_yar'],
        'survey_elite_yar' => ['elite_yar'],
        'survey_buyer' => ['buyer'],
    ];

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
            'elite_yar_report' => FinancialCodes::DOC_ELITE_YAR_REPORT,
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
            'tax_elite_yar' => FinancialCodes::DOC_TAX_INVOICE_ELITE_YAR,
            // Stage 7 — surveys
            'survey_buyer' => FinancialCodes::DOC_SURVEY,
            'survey_elite_yar' => FinancialCodes::DOC_SURVEY_ELITE_YAR,
        ];

        if (! array_key_exists($type, $typeMap)) {
            return error(__('orders.invalid_invoice_type'), [], 422);
        }

        // Per-document authorization: only the party the document concerns (or an admin)
        // may download it — not every order party.
        if (! $this->mayAccessDocument($order, $type)) {
            return error(__('global.forbidden'), [], 403);
        }

        $pdf = $generator->download($order, $typeMap[$type]);

        return $pdf->download("invoice-{$order->order_number}-{$type}.pdf");
    }

    /**
     * Whether the authenticated user is in the document's allowed-audience for this order.
     * Admins may access any document; unmapped types default to deny.
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

        $allowed = self::DOCUMENT_AUDIENCES[$type] ?? [];
        $userId = (int) $user->id;

        foreach ($allowed as $audience) {
            $matches = match ($audience) {
                'buyer' => (int) $order->{Order::PLACER_USER_ID} === $userId,
                'elite_yar' => in_array($userId, array_filter([
                    (int) $order->{Order::BENEFICIARY_USER_ID},
                    (int) $order->{Order::ELITE_YAR_USER_ID},
                ]), true),
                'supplier' => $order->items()->where(OrderItem::SUPPLIER_USER_ID, $userId)->exists(),
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
