<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SupplierRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * درخواست‌های تأمین‌کننده به بک‌آفیس.
 *
 * ## چرا درخواست و نه اقدام
 *
 * **لغوِ سفارش:** بند ۵.۳ لغوِ اجباری را *تنها* اختیارِ وضعیتیِ ادمین کرده و
 * بند ۳.۶ آن را به «تیکت + دلیل» مشروط می‌کند. یک اندپوینتِ «تأمین‌کننده
 * سفارش را لغو می‌کند» هر دو را دور می‌زد، و آن اقدام سفارشِ **پرداخت‌شده** را
 * برمی‌گرداند: استردادِ کیف پول، آزادسازیِ موجودی، اسنادِ لغو. پس اینجا فقط صف
 * ثبت می‌شود و `AdminOrderController::forceCancel` مسیرِ همیشگی می‌ماند.
 *
 * **افزودنِ کالا:** کاتالوگ عمداً ادمین‌محور است. نوشتنِ مستقیم یعنی هر
 * تأمین‌کننده نامِ کالای خودش را بسازد و کاتالوگ از هم بپاشد — و قیمت و موجودی
 * هم به همان واریانت‌های مشترک گره خورده‌اند.
 */
class SupplierRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', Rule::in(SupplierRequest::TYPES)],
            'status' => ['nullable', Rule::in([
                SupplierRequest::STATUS_PENDING,
                SupplierRequest::STATUS_APPROVED,
                SupplierRequest::STATUS_REJECTED,
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $requests = SupplierRequest::query()
            ->where(SupplierRequest::SUPPLIER_USER_ID, Auth::id())
            ->when($request->filled('type'), fn (Builder $q) => $q->where(SupplierRequest::TYPE, $request->input('type')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where(SupplierRequest::STATUS, $request->input('status')))
            ->with(['order', 'ticket'])
            ->latest(SupplierRequest::ID)
            ->paginate($this->perPage(20));

        return success($requests->through(fn (SupplierRequest $r) => $this->present($r)));
    }

    /**
     * درخواستِ لغوِ سفارش.
     *
     * فقط روی سفارشی که هنوز تسویه نشده: پس از باسکول پول جابه‌جا شده و
     * برگرداندنش مسیرِ دیگری دارد (مرجوعی/داوری)، نه لغو.
     */
    public function cancelOrder(Request $request, Order $order): JsonResponse
    {
        $this->authorize('manageAsSupplier', $order);

        $data = $request->validate([
            // همان `min:10` لغوِ اجباری: «دلیل» باید جمله باشد، وگرنه ادمین
            // چیزی برای تصمیم‌گیری ندارد.
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'reason.required' => __('supplier.requests.reason_required'),
            'reason.min' => __('supplier.requests.reason_too_short'),
        ]);

        if ($order->{Order::WEIGHING_COMPLETED_AT} !== null) {
            return error(__('supplier.requests.order_already_settled'), [], 422);
        }

        if (in_array($order->{Order::STATUS}, [Order::STATUS_CANCELLED, Order::STATUS_DELIVERED], true)) {
            return error(__('supplier.requests.order_not_cancellable'), [], 422);
        }

        $duplicate = SupplierRequest::query()
            ->where(SupplierRequest::SUPPLIER_USER_ID, Auth::id())
            ->where(SupplierRequest::ORDER_ID, $order->id)
            ->where(SupplierRequest::TYPE, SupplierRequest::TYPE_ORDER_CANCELLATION)
            ->where(SupplierRequest::STATUS, SupplierRequest::STATUS_PENDING)
            ->exists();

        if ($duplicate) {
            return error(__('supplier.requests.already_pending'), [], 409);
        }

        $supplierRequest = SupplierRequest::create([
            SupplierRequest::SUPPLIER_USER_ID => Auth::id(),
            SupplierRequest::TYPE => SupplierRequest::TYPE_ORDER_CANCELLATION,
            SupplierRequest::ORDER_ID => $order->id,
            SupplierRequest::REASON => $data['reason'],
        ]);

        return created($this->present($supplierRequest), __('supplier.requests.submitted'));
    }

    /** درخواستِ افزودنِ محصول/واریانت به کاتالوگ. */
    public function catalogAddition(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_name' => ['required', 'string', 'max:150'],
            'variant_name' => ['nullable', 'string', 'max:150'],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'specifications' => ['nullable', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'reason.required' => __('supplier.requests.reason_required'),
            'reason.min' => __('supplier.requests.reason_too_short'),
        ]);

        $supplierRequest = SupplierRequest::create([
            SupplierRequest::SUPPLIER_USER_ID => Auth::id(),
            SupplierRequest::TYPE => SupplierRequest::TYPE_CATALOG_ADDITION,
            SupplierRequest::REASON => $data['reason'],
            // جزئیاتِ نوعِ درخواست در `payload` می‌نشیند نه ستونِ اختصاصی —
            // وگرنه هر نوعِ تازه یعنی یک مهاجرتِ اسکیما.
            SupplierRequest::PAYLOAD => [
                'product_name' => $data['product_name'],
                'variant_name' => $data['variant_name'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'specifications' => $data['specifications'] ?? null,
            ],
        ]);

        return created($this->present($supplierRequest), __('supplier.requests.submitted'));
    }

    /**
     * انصراف از درخواستِ هنوز بررسی‌نشده.
     *
     * بررسی‌شده حذف نمی‌شود: تصمیمِ ادمین و دلیلش سابقه‌اند.
     */
    public function destroy(SupplierRequest $supplierRequest): JsonResponse
    {
        if ((int) $supplierRequest->{SupplierRequest::SUPPLIER_USER_ID} !== (int) Auth::id()) {
            return error(__('global.forbidden'), [], 403);
        }

        if (! $supplierRequest->isPending()) {
            return error(__('supplier.requests.already_reviewed'), [], 422);
        }

        $supplierRequest->delete();

        return success(null, __('supplier.requests.withdrawn'));
    }

    /** @return array<string, mixed> */
    private function present(SupplierRequest $r): array
    {
        return [
            'id' => $r->getKey(),
            SupplierRequest::TYPE => $r->{SupplierRequest::TYPE},
            SupplierRequest::STATUS => $r->{SupplierRequest::STATUS},
            SupplierRequest::ORDER_ID => $r->{SupplierRequest::ORDER_ID},
            'order_number' => $r->order?->{Order::ORDER_NUMBER},
            SupplierRequest::TICKET_ID => $r->{SupplierRequest::TICKET_ID},
            SupplierRequest::REASON => $r->{SupplierRequest::REASON},
            SupplierRequest::PAYLOAD => $r->{SupplierRequest::PAYLOAD},
            // پاسخِ بک‌آفیس — بدونِ این، «رد شد» همان بن‌بستی می‌شود که در
            // ردِ درخواستِ تأمین‌کنندگی داشتیم.
            SupplierRequest::REVIEW_NOTE => $r->{SupplierRequest::REVIEW_NOTE},
            SupplierRequest::REVIEWED_AT => $r->{SupplierRequest::REVIEWED_AT},
            'created_at' => $r->created_at,
        ];
    }
}
