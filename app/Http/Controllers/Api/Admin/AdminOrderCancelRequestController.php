<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Order\OrderCancelRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CancelRequestReasonRequest;
use App\Models\Order;
use App\Models\OrderCancelRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * صفِ لغوهای ارجاع‌شده به بک‌آفیس — پرسش ۴ مدیریت: «اگر یکی مخالف بود → ارجاع
 * به بک‌آفیس و تصمیم نهایی آن‌جا.»
 *
 * ⚠️ برخلافِ `AdminSupplierRequestController`، **پذیرش این‌جا واقعاً لغو می‌کند**
 * و پول را برمی‌گرداند (`CancelOrderAction` → `RefundOrderToWalletAction`). آن
 * صف عمداً بی‌اثر بود چون بند ۳.۶ لغوِ اجباری را به «تیکت + دلیل» مشروط می‌کرد و
 * نمی‌خواست مسیرِ دومی بی‌آن دو شرط باز شود. این‌جا هر دو شرط به شکلِ خودشان
 * برقرارند: خودِ درخواست — با دلیلِ درخواست‌کننده و دلیلِ ردِ طرفِ مقابل — همان
 * پرونده‌ای است که تیکت قرار بود باشد، و `reason` ادمین اجباری است (`min:10`).
 * `admin.level:2` روی پذیرش، مثلِ `force-cancel`: جابه‌جاییِ واقعیِ پول.
 *
 * فقط ردیفِ `escalated` قابلِ تصمیم است: تا وقتی طرفِ مقابل مهلت دارد، بک‌آفیس
 * واردِ توافقِ دو نفر نمی‌شود.
 */
class AdminOrderCancelRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(OrderCancelRequest::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $rows = OrderCancelRequest::query()
            ->with(['order.items', 'requester.profile', 'admin.profile'])
            ->when(
                $request->filled('status'),
                fn (Builder $q) => $q->where(OrderCancelRequest::STATUS, $request->input('status')),
                // پیش‌فرض صفِ کار است: آنچه منتظرِ تصمیمِ بک‌آفیس است.
                fn (Builder $q) => $q->where(OrderCancelRequest::STATUS, OrderCancelRequest::STATUS_ESCALATED),
            )
            ->latest(OrderCancelRequest::ID)
            ->paginate($this->perPage(20));

        return success($rows->through(fn (OrderCancelRequest $r) => $this->present($r)));
    }

    public function approve(CancelRequestReasonRequest $request, OrderCancelRequest $orderCancelRequest, OrderCancelRequestAction $action): JsonResponse
    {
        $action->adminApprove($orderCancelRequest, (int) Auth::id(), $request->validated('reason'));

        activity()
            ->performedOn($orderCancelRequest->order)
            ->causedBy(Auth::user())
            ->withProperties(['reason' => $request->validated('reason'), 'cancel_request_id' => $orderCancelRequest->getKey()])
            ->log('admin.order.cancel_request_approved');

        return success($this->present($orderCancelRequest->refresh()), __('admin.order_cancel_requests.approved'));
    }

    public function reject(CancelRequestReasonRequest $request, OrderCancelRequest $orderCancelRequest, OrderCancelRequestAction $action): JsonResponse
    {
        $action->adminReject($orderCancelRequest, (int) Auth::id(), $request->validated('reason'));

        activity()
            ->performedOn($orderCancelRequest->order)
            ->causedBy(Auth::user())
            ->withProperties(['reason' => $request->validated('reason'), 'cancel_request_id' => $orderCancelRequest->getKey()])
            ->log('admin.order.cancel_request_rejected');

        return success($this->present($orderCancelRequest->refresh()), __('admin.order_cancel_requests.rejected'));
    }

    /** @return array<string, mixed> */
    private function present(OrderCancelRequest $r): array
    {
        $r->loadMissing(['order.items', 'requester.profile', 'admin.profile']);
        $order = $r->order;

        return [
            'id' => $r->getKey(),
            OrderCancelRequest::STATUS => $r->{OrderCancelRequest::STATUS},
            OrderCancelRequest::ORDER_ID => $r->{OrderCancelRequest::ORDER_ID},
            'order_number' => $order?->{Order::ORDER_NUMBER},
            'order_status' => $order?->{Order::STATUS},
            // رقمی که با پذیرش جابه‌جا می‌شود: از تأمین‌کننده (PTS) و به خریدار (POO).
            'payment_of_owner' => $order?->{Order::PAYMENT_OF_OWNER} !== null ? (string) $order->{Order::PAYMENT_OF_OWNER} : null,
            'payment_to_supplier' => $order?->{Order::PAYMENT_TO_SUPPLIER} !== null ? (string) $order->{Order::PAYMENT_TO_SUPPLIER} : null,
            OrderCancelRequest::REQUESTED_BY => $r->{OrderCancelRequest::REQUESTED_BY},
            'requester' => [
                'user_id' => $r->{OrderCancelRequest::REQUESTER_USER_ID},
                'name' => $r->requester?->profile?->name,
                'mobile' => $r->requester?->mobile,
            ],
            OrderCancelRequest::REASON => $r->{OrderCancelRequest::REASON},
            OrderCancelRequest::RESPOND_UNTIL => $r->{OrderCancelRequest::RESPOND_UNTIL},
            OrderCancelRequest::COUNTERPARTY_DECISION => $r->{OrderCancelRequest::COUNTERPARTY_DECISION},
            OrderCancelRequest::COUNTERPARTY_REASON => $r->{OrderCancelRequest::COUNTERPARTY_REASON},
            OrderCancelRequest::RESPONDED_AT => $r->{OrderCancelRequest::RESPONDED_AT},
            OrderCancelRequest::ESCALATED_AT => $r->{OrderCancelRequest::ESCALATED_AT},
            OrderCancelRequest::ADMIN_DECISION => $r->{OrderCancelRequest::ADMIN_DECISION},
            OrderCancelRequest::ADMIN_REASON => $r->{OrderCancelRequest::ADMIN_REASON},
            'admin_name' => $r->admin instanceof User ? $r->admin->profile?->name : null,
            OrderCancelRequest::DECIDED_AT => $r->{OrderCancelRequest::DECIDED_AT},
            'created_at' => $r->created_at,
        ];
    }
}
