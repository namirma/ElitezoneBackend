<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Product\ProductChangeRequestResource;
use App\Models\ProductChangeRequest;
use App\Services\Product\ProductChangeRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * صفِ درخواست‌های تغییرِ کاتالوگ از سوی تأمین‌کننده‌ها.
 *
 * ⚠️ برخلافِ `AdminSupplierRequestController`، پذیرش این‌جا **واقعاً اعمال
 * می‌کند** — از همان `ProductCatalogWriter` که خودِ پنلِ ادمین با آن محصول
 * می‌سازد. دلیلش همان دلیلِ قیمت است: مسیرِ اجراییِ دیگری وجود ندارد، و
 * «تأییدِ بی‌اثر» یعنی ادمین همان مشخصات را دستی دوباره تایپ کند.
 */
class AdminProductChangeRequestController extends Controller
{
    public function __construct(private readonly ProductChangeRequestService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(ProductChangeRequest::STATUSES)],
            'type' => ['nullable', Rule::in(ProductChangeRequest::TYPES)],
            'supplier_user_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $requests = ProductChangeRequest::query()
            ->with(['supplier.profile', 'product', 'variant'])
            ->when(
                $request->filled('status'),
                fn (Builder $q) => $q->where(ProductChangeRequest::STATUS, $request->input('status')),
                // پیش‌فرض صفِ کار است نه کلِ تاریخچه.
                fn (Builder $q) => $q->pending()
            )
            ->when($request->filled('type'), fn (Builder $q) => $q->where(ProductChangeRequest::TYPE, $request->input('type')))
            ->when($request->filled('supplier_user_id'), fn (Builder $q) => $q->where(ProductChangeRequest::SUPPLIER_USER_ID, $request->integer('supplier_user_id')))
            ->latest(ProductChangeRequest::ID)
            ->paginate($this->perPage(20));

        return success(ProductChangeRequestResource::collection($requests), __('product_changes.list_received'));
    }

    public function show(ProductChangeRequest $changeRequest): JsonResponse
    {
        return success(
            new ProductChangeRequestResource($changeRequest->load(['supplier.profile', 'product', 'variant'])),
            __('product_changes.received')
        );
    }

    public function approve(Request $request, ProductChangeRequest $changeRequest): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $decided = $this->service->approve($changeRequest, $request->user(), $data['note'] ?? null);

        $this->logDecision($decided, $data['note'] ?? null);

        return success(
            new ProductChangeRequestResource($decided->load(['supplier.profile', 'product', 'variant'])),
            __('product_changes.approved')
        );
    }

    public function reject(Request $request, ProductChangeRequest $changeRequest): JsonResponse
    {
        // ردِ بی‌دلیل همان بن‌بستی است که در ردِ درخواستِ تأمین‌کنندگی داشتیم.
        $data = $request->validate(
            ['note' => ['required', 'string', 'max:1000']],
            ['note.required' => __('product_changes.note_required')],
        );

        $decided = $this->service->reject($changeRequest, $request->user(), $data['note']);

        $this->logDecision($decided, $data['note']);

        return success(
            new ProductChangeRequestResource($decided->load(['supplier.profile', 'product', 'variant'])),
            __('product_changes.rejected')
        );
    }

    private function logDecision(ProductChangeRequest $request, ?string $note): void
    {
        activity()
            ->performedOn($request)
            ->causedBy(Auth::user())
            ->withProperties(['status' => $request->{ProductChangeRequest::STATUS}, 'note' => $note])
            ->log('admin.product_change_request.decided');
    }
}
