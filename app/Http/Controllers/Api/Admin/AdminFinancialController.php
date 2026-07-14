<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FinancialFilterRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use App\Services\Financial\FinancialHealthService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminFinancialController extends Controller
{
    public function __construct(private readonly FinancialHealthService $healthService) {}

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
                    ->when($request->filled('payment_status'), fn ($q) => $q->where(Order::PAYMENT_STATUS, $request->payment_status));
            })
            // فیلتر فاکتور نهایی
            ->when($type === 'final', function (Builder $query) {
                $query->whereNotNull(Order::FINAL_INVOICE_REF)
                    ->whereIn(Order::STATUS, [Order::STATUS_WEIGHBRIDGE_DONE, Order::STATUS_DELIVERED]);
            })
            // فیلتر دستور سفارش
            ->when($type === 'command', fn (Builder $query) => $query->whereNotNull(Order::ESPADANA_ORDER_REF))
            // فیلتر فاکتور رسمی/مالیاتی
            ->when($type === 'tax', fn (Builder $query) => $query->whereNotNull(Order::TAX_INVOICE_REF))
            ->latest(Order::CREATED_AT)
            ->paginate($this->perPage());

        return success($invoices, __('admin.invoices_list_success'));
    }

    /**
     * دریافت سهم‌های الیت یار (تخصیص‌یافته یا پرداخت‌شده)
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
                WalletTransaction::TYPE_WITHDRAW,
            ]);
        }

        $data = $shares->latest(WalletTransaction::CREATED_AT)->paginate($this->perPage());

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
            ->when($docType === 'waybill', fn (Builder $query) => $query->whereNotNull(Order::WAYBILL_REF))
            ->when($docType === 'tax', fn (Builder $query) => $query->whereNotNull('payment_receipt_ref'))
            ->latest(Order::UPDATED_AT)
            ->paginate($this->perPage());

        return success($documents, __('admin.supplier_docs_success'));
    }

    /**
     * دریافت اسناد مالی الیت یار
     */
    public function getExpertDocuments(FinancialFilterRequest $request): JsonResponse
    {
        $documents = WalletTransaction::query()
            ->with('wallet.user.profile')
            ->where(WalletTransaction::TYPE, WalletTransaction::TYPE_WITHDRAW)
            ->whereNotNull(WalletTransaction::META.'->invoice_path') // چک کردن وجود فایل فاکتور در متادیتا
            ->latest(WalletTransaction::CREATED_AT)
            ->paginate($this->perPage());

        return success($documents, __('admin.expert_docs_success'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Financial Health — مشاهده سلامت مالی سیستم
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * سفارشاتی که سوپلایر بدهی باسکول دارد و هنوز تصفیه نشده‌اند
     *
     * (orders.supplier_debt_pending = true)
     */
    public function pendingDebts(): JsonResponse
    {
        $orders = Order::query()
            ->where(Order::SUPPLIER_DEBT_PENDING, true)
            ->with(['placer.profile', 'items.originFactory'])
            ->latest()
            ->paginate($this->perPage(20));

        return success(OrderResource::collection($orders), __('admin.financial.pending_debts_received'));
    }

    /**
     * سفارشاتی که باسکول شده‌اند اما یک یا چند سند مودیان هنوز ثبت نشده
     *
     * Doc Q (eliteYAR) | Doc O (supplier) | Doc P (system/Saze)
     */
    public function pendingMoadianInvoices(): JsonResponse
    {
        $orders = Order::query()
            ->whereNotNull(Order::WEIGHING_COMPLETED_AT)
            ->where(function ($q) {
                $q->whereNull(Order::ELITE_YAR_MOADIAN_REF)
                    ->orWhereNull(Order::SUPPLIER_MOADIAN_REF)
                    ->orWhereNull(Order::SYSTEM_MOADIAN_REF);
            })
            ->whereIn(Order::STATUS, [
                Order::STATUS_WEIGHBRIDGE_DONE,
                Order::STATUS_WAYBILL_ISSUED,
                Order::STATUS_DELIVERED,
                Order::STATUS_COMPLETED,
            ])
            ->with(['placer.profile'])
            ->latest()
            ->paginate($this->perPage(20));

        $enriched = $orders->through(fn (Order $order) => [
            'id'                         => $order->id,
            'order_number'               => $order->{Order::ORDER_NUMBER},
            'status'                     => $order->{Order::STATUS},
            'placer'                     => $order->placer?->profile?->name,
            'final_amount'               => (float) $order->{Order::FINAL_AMOUNT},
            'weighing_completed_at'      => $order->{Order::WEIGHING_COMPLETED_AT},
            'elite_yar_moadian_done'    => $order->{Order::ELITE_YAR_MOADIAN_REF} !== null,
            'supplier_moadian_done'      => $order->{Order::SUPPLIER_MOADIAN_REF} !== null,
            'system_moadian_done'        => $order->{Order::SYSTEM_MOADIAN_REF} !== null,
            'has_beneficiary'            => $order->{Order::BENEFICIARY_USER_ID} !== null,
        ]);

        return success($enriched, __('admin.financial.pending_moadian_received'));
    }

    /**
     * سلامت مالی کلی پلتفرم
     *
     * شامل: موجودی کیف‌پول سیستم و VAT، جمع کل موجودی/هولد تمام کیف‌پول‌ها،
     * واریزها و برداشت‌های معلق، بدهی‌های سوپلایر
     */
    public function financialHealth(): JsonResponse
    {
        return success($this->healthService->getReport(), __('admin.financial.health_received'));
    }
}
