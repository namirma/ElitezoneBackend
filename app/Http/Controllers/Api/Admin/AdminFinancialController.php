<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FinancialFilterRequest;
use App\Models\Order;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder; // کلاس صحیح برای کوئری‌ها
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminFinancialController extends Controller
{
    /**
     * دریافت لیست فاکتورهای شرکت (اولیه، نهایی، دستور سفارش، مالیاتی)
     */
    public function getCompanyInvoices(FinancialFilterRequest $request): JsonResponse
    {
        $type = $request->input('type', 'initial');

        $invoices = Order::query()
            ->with(['placer.profile', 'items.productVariant'])
            // فیلتر فاکتور اولیه
            ->when($type === 'initial', function (Builder $query) use ($request) {
                $query->whereNotNull(Order::INITIAL_INVOICE_REF)
                      ->when($request->filled('payment_status'), fn($q) => $q->where(Order::PAYMENT_STATUS, $request->payment_status));
            })
            // فیلتر فاکتور نهایی
            ->when($type === 'final', function (Builder $query) {
                $query->whereNotNull(Order::FINAL_INVOICE_REF)
                      ->whereIn(Order::STATUS, [Order::STATUS_WEIGHBRIDGE_DONE, Order::STATUS_DELIVERED]);
            })
            // فیلتر دستور سفارش
            ->when($type === 'command', fn(Builder $query) => $query->whereNotNull(Order::ESPADANA_ORDER_REF))
            // فیلتر فاکتور رسمی/مالیاتی
            ->when($type === 'tax', fn(Builder $query) => $query->whereNotNull(Order::TAX_INVOICE_REF))
            ->latest(Order::CREATED_AT)
            ->paginate($request->input('per_page', 20));

        return success($invoices, __('admin.invoices_list_success'));
    }

    /**
     * دریافت سهم‌های مشاور فنی (تخصیص‌یافته یا پرداخت‌شده)
     */
    public function getExpertShares(Request $request): JsonResponse
    {
        $mode = $request->input('mode', 'allocation');

        $shares = WalletTransaction::query()
            // دسترسی به اطلاعات کاربر از طریق رابطه کیف پول
            ->with(['wallet.user.profile', 'order'])

            // فیلتر کردن بر اساس نقش کاربر (مهندس یا پیمانکار)
            ->whereHas('wallet.user.roles', function (Builder $query) {
                $query->whereIn('name', ['engineer', 'contractor']);
            });

        // فیلتر بر اساس حالت (تخصیص یا پرداخت)
        if ($mode === 'allocation') {
            $shares->where(WalletTransaction::TYPE, WalletTransaction::TYPE_FEE_HOLD);
        } elseif ($mode === 'payment') {
            $shares->whereIn(WalletTransaction::TYPE, [
                WalletTransaction::TYPE_FEE_RELEASE,
                WalletTransaction::TYPE_WITHDRAW
            ]);
        }

        $data = $shares->latest(WalletTransaction::CREATED_AT)->paginate(20);

        return success($data, __('admin.expert_shares_success'));
    }

    /**
     * دریافت اسناد مالی تامین‌کننده (بارنامه یا فاکتور رسمی)
     */
    public function getSupplierDocuments(FinancialFilterRequest $request): JsonResponse
    {
        $docType = $request->input('doc_type', 'waybill');

        $documents = Order::query()
            ->with(['items.supplier.profile', 'items.factory'])
            ->when($docType === 'waybill', fn(Builder $query) => $query->whereNotNull(Order::WAYBILL_REF))
            ->when($docType === 'tax', fn(Builder $query) => $query->whereNotNull('payment_receipt_ref'))
            ->latest(Order::UPDATED_AT)
            ->paginate($request->input('per_page', 20));

        return success($documents, __('admin.supplier_docs_success'));
    }

    /**
     * دریافت اسناد مالی مشاور فنی
     */
    public function getExpertDocuments(FinancialFilterRequest $request): JsonResponse
    {
        $documents = WalletTransaction::query()
            ->with('wallet.user.profile')
            ->where(WalletTransaction::TYPE, WalletTransaction::TYPE_WITHDRAW)
            ->whereNotNull(WalletTransaction::META . '->invoice_path') // چک کردن وجود فایل فاکتور در متادیتا
            ->latest(WalletTransaction::CREATED_AT)
            ->paginate($request->input('per_page', 20));

        return success($documents, __('admin.expert_docs_success'));
    }
}
