<?php

namespace App\Http\Controllers\Api\Admin;

use App\Constants\FinancialCodes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FinancialFilterRequest;
use App\Http\Resources\Financial\WalletTransactionResource;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use App\Models\WalletTransaction;
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
            //
            // ⚠️ این شرط `whereNotNull(Order::INITIAL_INVOICE_REF)` بود و
            // **هیچ‌کس آن ستون را نمی‌نویسد** — نه اکشنی، نه سیدری، نه مهاجرتی.
            // یعنی تبِ «فاکتور اولیه»، که تبِ **پیش‌فرضِ** همین اندپوینت است،
            // همیشه خالی برمی‌گشت. نه خطایی، نه صفر-با-توضیح: فقط یک فهرستِ تهی
            // که شبیهِ «هنوز فاکتوری صادر نشده» به نظر می‌رسید.
            //
            // ستونِ ref برای فاکتورِ اولیه اصلاً منبعی ندارد: برخلافِ
            // `final_invoice_ref` که مرجعی است که کاربر ثبت می‌کند، فاکتورِ اولیه
            // را خودمان تولید می‌کنیم و در جدولِ `invoices` می‌نشیند. پس همان‌جا
            // پرسیده می‌شود — سند A (خریدِ مستقیم) یا E (رزرو).
            ->when($type === 'initial', function (Builder $query) use ($request) {
                $query->whereHas('invoices', fn ($q) => $q->whereIn('document_code', [
                    FinancialCodes::DOC_RECEIPT_OWNER,
                    FinancialCodes::DOC_RESERVE_RECEIPT,
                ]))
                    ->when($request->filled('payment_status'), fn ($q) => $q->where(Order::PAYMENT_STATUS, $request->payment_status));
            })
            // فیلتر فاکتور نهایی
            ->when($type === 'final', function (Builder $query) {
                $query->whereNotNull(Order::FINAL_INVOICE_REF)
                    ->whereIn(Order::STATUS, [
                        Order::STATUS_SHIPPED,
                        Order::STATUS_PARTIALLY_DELIVERED,
                        Order::STATUS_DELIVERED,
                    ]);
            })
            // فیلتر دستور سفارش
            ->when($type === 'command', fn (Builder $query) => $query->whereNotNull(Order::ESPADANA_ORDER_REF))
            // فیلتر فاکتور رسمی/مالیاتی
            //
            // ⚠️ همان داستان: `tax_invoice_ref` هم هیچ نویسنده‌ای ندارد. جانشینش
            // در بازطراحی `system_moadian_ref` است — سند P، فاکتورِ مودیانِ شرکت
            // برای خریدار، که `RecordSystemMoadianInvoiceAction` ثبتش می‌کند.
            ->when($type === 'tax', fn (Builder $query) => $query->whereNotNull(Order::SYSTEM_MOADIAN_REF))
            ->latest(Order::CREATED_AT)
            ->paginate($this->perPage());

        return success(OrderResource::collection($invoices), __('admin.invoices_list_success'));
    }

    /**
     * دریافت سهم‌های الیت یار (تخصیص‌یافته یا پرداخت‌شده)
     */
    public function getExpertShares(Request $request): JsonResponse
    {
        $mode = $request->input('mode', 'allocation');

        // ⚠️ هر دو حالتِ این گزارش روی نوع‌هایی فیلتر می‌کردند که مسیرِ الیت‌یار
        // **هرگز نمی‌نویسد**:
        //
        //   allocation → `fee_hold`     تنها نویسنده‌اش `placeHold()` است و هر دو
        //                               فراخوانش hold را روی کیف پولِ **تأمین‌کننده**
        //                               می‌گذارند؛ تأمین‌کننده هم نقشِ engineer/contractor
        //                               ندارد، پس اشتراکِ دو شرط همیشه تهی بود.
        //   payment    → `fee_release`  هیچ نویسنده‌ای ندارد، و `withdraw` هر برداشتی
        //                               را می‌گیرد نه کمیسیون را.
        //
        // فیلترِ نقش هم کهنه بود: مهاجرتِ `add_elite_yar_role_and_backfill` صراحتاً
        // `engineer`/`contractor` را از معنیِ «الیت‌یار» جدا کرد.
        //
        // تعریفِ دقیقِ «لِگِ کیف پولِ الیت‌یار» خودِ کدِ کیف پول است:
        // `WalletTransactionEngine::process()` روی هر ردیف `meta.code` می‌گذارد و
        // برای این مسیر همیشه `WEY` است. این هم مستقل از نقش است و هم دقیقاً همان
        // چیزی که گزارش می‌خواهد.
        $shares = WalletTransaction::query()
            ->with(['wallet.user.profile', 'order'])
            ->where(WalletTransaction::META.'->code', FinancialCodes::WALLET_ELITE_YAR);

        if ($mode === 'allocation') {
            // کمیسیونی که سرِ باسکول امانی پارک شده و هنوز سند Q نیامده.
            $shares->where(WalletTransaction::STATUS, FinancialCodes::STATUS_HOLD);
        } elseif ($mode === 'payment') {
            // آزادسازی با ثبتِ فاکتور مودیانِ الیت‌یار (`ReleaseEliteYARHoldAction`).
            $shares->where(WalletTransaction::TYPE, WalletTransaction::TYPE_HOLD_RELEASE);
        }

        $data = $shares->latest(WalletTransaction::CREATED_AT)->paginate($this->perPage());

        return success(WalletTransactionResource::collection($data), __('admin.elite_yar_shares_success'));
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
            // ⚠️ این شرط `payment_receipt_ref` بود — ستونی که واقعاً نوشته می‌شود،
            // ولی مسیرِ فیشِ **تصویریِ پرداخت** است نه سندِ مالیاتی. پس تبِ
            // «فاکتور رسمی» سفارش‌هایی را می‌آورد که فیش آپلود کرده‌اند و دقیقاً
            // همان سفارش‌هایی را که فاکتور مودیانِ تأمین‌کننده دارند جا می‌انداخت.
            // سندِ مالیاتیِ تأمین‌کننده سند O است و مرجعش این ستون است.
            ->when($docType === 'tax', fn (Builder $query) => $query->whereNotNull(Order::SUPPLIER_MOADIAN_REF))
            ->latest(Order::UPDATED_AT)
            ->paginate($this->perPage());

        return success(OrderResource::collection($documents), __('admin.supplier_docs_success'));
    }

    /**
     * دریافت اسناد مالی الیت یار
     */
    public function getExpertDocuments(FinancialFilterRequest $request): JsonResponse
    {
        // ⚠️ این اندپوینت **همیشه تهی** برمی‌گشت. شرطش
        // `whereNotNull(meta->invoice_path)` بود و `invoice_path` در کلِ مخزن —
        // شاملِ تست‌ها — فقط در همین یک خط ظاهر می‌شود: هیچ‌چیز هرگز آن کلید را
        // در `wallet_transactions.meta` نمی‌نویسد. (قرینه‌اش `receipt_path` است
        // که واقعاً نوشته می‌شود.) نه یک تبِ خالی، کلِ اندپوینت.
        //
        // سندِ مالیِ الیت‌یار همان سند Q است و مرجعش روی سفارش می‌نشیند، نه روی
        // تراکنشِ کیف پول. پس این گزارش قرینه‌ی `getSupplierDocuments` می‌شود:
        // سفارش‌هایی که فاکتور مودیانِ الیت‌یار برایشان ثبت شده.
        $documents = Order::query()
            ->with(['eliteYAR.profile', 'items.productVariant'])
            ->whereNotNull(Order::ELITE_YAR_MOADIAN_REF)
            ->latest(Order::UPDATED_AT)
            ->paginate($this->perPage());

        return success(OrderResource::collection($documents), __('admin.elite_yar_docs_success'));
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
            // سند Q فقط وقتی «معلق» است که سفارش اصلاً الیت‌یار داشته باشد.
            //
            // `ReleaseEliteYARHoldAction` فقط به `order.elite_yar_user_id` اجازهٔ
            // ثبت می‌دهد، پس روی سفارشِ بدونِ الیت‌یار **هیچ‌کس** نمی‌تواند Doc Q
            // را ثبت کند و `elite_yar_moadian_ref` تا ابد NULL می‌ماند. با شرطِ
            // قبلی چنین سفارشی برای همیشه در صفِ «منتظر فاکتور مودیان» می‌ماند و
            // شمارندهٔ صف فقط بالا می‌رفت — یعنی صف عملاً غیرقابل‌خالی‌شدن بود و
            // ادمین نمی‌توانست تشخیص دهد کدام ردیف واقعاً کار دارد.
            ->where(function ($q) {
                $q->where(function ($eliteYar) {
                    $eliteYar->whereNotNull(Order::ELITE_YAR_USER_ID)
                        ->whereNull(Order::ELITE_YAR_MOADIAN_REF);
                })
                    ->orWhereNull(Order::SUPPLIER_MOADIAN_REF)
                    ->orWhereNull(Order::SYSTEM_MOADIAN_REF);
            })
            ->whereIn(Order::STATUS, [
                Order::STATUS_SHIPPED,
                Order::STATUS_PARTIALLY_DELIVERED,
                Order::STATUS_DELIVERED,
            ])
            ->with(['placer.profile'])
            ->latest()
            ->paginate($this->perPage(20));

        $enriched = $orders->through(fn (Order $order) => [
            'id' => $order->id,
            'order_number' => $order->{Order::ORDER_NUMBER},
            'status' => $order->{Order::STATUS},
            'placer' => $order->placer?->profile?->name,
            'final_amount' => (float) $order->{Order::FINAL_AMOUNT},
            'weighing_completed_at' => $order->{Order::WEIGHING_COMPLETED_AT},
            'elite_yar_moadian_done' => $order->{Order::ELITE_YAR_MOADIAN_REF} !== null,
            'supplier_moadian_done' => $order->{Order::SUPPLIER_MOADIAN_REF} !== null,
            'system_moadian_done' => $order->{Order::SYSTEM_MOADIAN_REF} !== null,
            'has_beneficiary' => $order->{Order::BENEFICIARY_USER_ID} !== null,
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
