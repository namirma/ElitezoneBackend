<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SupplierRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * صفِ بررسیِ درخواست‌های تأمین‌کننده.
 *
 * ⚠️ **پذیرش اینجا هیچ کاری نمی‌کند جز ثبتِ تصمیم.**
 *
 * وسوسه‌ی طبیعی این است که `approve` روی یک درخواستِ لغو، خودش
 * `CancelOrderAction` را صدا بزند. عمداً نمی‌زند: بند ۳.۶ لغوِ اجباری را به
 * **تیکت و دلیل** مشروط کرده و `forceCancel` هر دو را الزامی می‌گیرد. اگر
 * پذیرشِ این صف هم لغو می‌کرد، یک مسیرِ دومِ لغو ساخته می‌شد که آن دو شرط را
 * ندارد — و لغو تنها اقدامی است که سفارشِ پرداخت‌شده را یک‌طرفه برمی‌گرداند.
 *
 * پس این صف فقط می‌گوید «موافقم»؛ اجرا از همان `POST /admin/orders/{order}/force-cancel`
 * انجام می‌شود، با تیکت و دلیلِ خودش.
 */
class AdminSupplierRequestController extends Controller
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
            ->with(['supplier.profile', 'order'])
            ->when($request->filled('type'), fn (Builder $q) => $q->where(SupplierRequest::TYPE, $request->input('type')))
            ->when(
                $request->filled('status'),
                fn (Builder $q) => $q->where(SupplierRequest::STATUS, $request->input('status')),
                // پیش‌فرض صفِ کار است نه کلِ تاریخچه.
                fn (Builder $q) => $q->where(SupplierRequest::STATUS, SupplierRequest::STATUS_PENDING)
            )
            ->latest(SupplierRequest::ID)
            ->paginate($this->perPage(20));

        return success($requests->through(fn (SupplierRequest $r) => $this->present($r)));
    }

    public function approve(Request $request, SupplierRequest $supplierRequest): JsonResponse
    {
        return $this->review($request, $supplierRequest, SupplierRequest::STATUS_APPROVED);
    }

    public function reject(Request $request, SupplierRequest $supplierRequest): JsonResponse
    {
        return $this->review($request, $supplierRequest, SupplierRequest::STATUS_REJECTED);
    }

    private function review(Request $request, SupplierRequest $supplierRequest, string $status): JsonResponse
    {
        $data = $request->validate([
            // ردِ بی‌دلیل همان بن‌بستی است که در ردِ درخواستِ تأمین‌کنندگی
            // داشتیم: تأمین‌کننده «رد شد» می‌بیند و نمی‌داند چه کند.
            'note' => [$status === SupplierRequest::STATUS_REJECTED ? 'required' : 'nullable', 'string', 'max:1000'],
        ], [
            'note.required' => __('admin.supplier_requests.note_required'),
        ]);

        // بررسیِ دوباره‌ی یک درخواستِ بسته‌شده یعنی بازنویسیِ تصمیمِ قبلی و
        // نامِ بررسی‌کننده — سابقه است، نه فیلدِ قابلِ ویرایش.
        $applied = DB::transaction(function () use ($supplierRequest, $status, $data) {
            /** @var SupplierRequest $locked */
            $locked = SupplierRequest::whereKey($supplierRequest->getKey())->lockForUpdate()->first();

            if (! $locked->isPending()) {
                return false;
            }

            $locked->forceFill([
                SupplierRequest::STATUS => $status,
                SupplierRequest::REVIEW_NOTE => $data['note'] ?? null,
                SupplierRequest::REVIEWED_BY => Auth::id(),
                SupplierRequest::REVIEWED_AT => now(),
            ])->save();

            return true;
        });

        if (! $applied) {
            return error(__('admin.supplier_requests.already_reviewed'), [], 409);
        }

        activity()
            ->performedOn($supplierRequest)
            ->causedBy(Auth::user())
            ->withProperties(['status' => $status, 'note' => $data['note'] ?? null])
            ->log('admin.supplier_request.reviewed');

        return success(
            $this->present($supplierRequest->refresh()),
            __('admin.supplier_requests.reviewed')
        );
    }

    /** @return array<string, mixed> */
    private function present(SupplierRequest $r): array
    {
        return [
            'id' => $r->getKey(),
            SupplierRequest::TYPE => $r->{SupplierRequest::TYPE},
            SupplierRequest::STATUS => $r->{SupplierRequest::STATUS},
            'supplier' => [
                'user_id' => $r->{SupplierRequest::SUPPLIER_USER_ID},
                'name' => $r->supplier?->profile?->name,
            ],
            SupplierRequest::ORDER_ID => $r->{SupplierRequest::ORDER_ID},
            'order_number' => $r->order?->{Order::ORDER_NUMBER},
            SupplierRequest::REASON => $r->{SupplierRequest::REASON},
            SupplierRequest::PAYLOAD => $r->{SupplierRequest::PAYLOAD},
            SupplierRequest::REVIEW_NOTE => $r->{SupplierRequest::REVIEW_NOTE},
            SupplierRequest::REVIEWED_AT => $r->{SupplierRequest::REVIEWED_AT},
            'created_at' => $r->created_at,
        ];
    }
}
