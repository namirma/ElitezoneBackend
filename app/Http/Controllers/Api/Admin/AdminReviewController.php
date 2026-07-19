<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Product\ProductReviewResource;
use App\Models\ProductReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * پنل مدیریت — صف تأیید نظرات محصول (ماژول ۹ نیازمندی فرانت BackOffice).
 *
 * همان الگوی صف‌های تأیید job-experiences: لیست + approve + reject (با دلیل الزامی ≤ ۱۰۰۰).
 * STATUS و REJECTION_REASON عمداً fillable نیستند — اینجا با forceFill نوشته می‌شوند.
 */
class AdminReviewController extends Controller
{
    // GET /admin/reviews
    public function index(Request $request): JsonResponse
    {
        $query = ProductReview::query()
            ->with(['user.profile', 'product'])
            ->orderByDesc(ProductReview::ID);

        if ($request->filled('status')) {
            $request->validate([
                'status' => [Rule::in(ProductReview::STATUSES)],
            ]);
            $query->where(ProductReview::STATUS, $request->string('status'));
        }

        if ($request->filled('product_id')) {
            $query->where(ProductReview::PRODUCT_ID, $request->integer('product_id'));
        }

        return success(ProductReviewResource::collection($query->paginate($this->perPage())), __('admin.reviews.list_received'));
    }

    // GET /admin/reviews/{review}
    public function show(ProductReview $review): JsonResponse
    {
        return success(new ProductReviewResource($review->load(['user.profile', 'product'])), __('admin.reviews.received'));
    }

    // POST /admin/reviews/{review}/approve
    public function approve(ProductReview $review): JsonResponse
    {
        $review->forceFill([
            ProductReview::STATUS => ProductReview::STATUS_APPROVED,
            ProductReview::REJECTION_REASON => null,
        ])->save();

        return success(new ProductReviewResource($review->refresh()), __('admin.reviews.approved'));
    }

    // POST /admin/reviews/{review}/reject
    public function reject(Request $request, ProductReview $review): JsonResponse
    {
        $validated = $request->validate(
            ['rejection_reason' => ['required', 'string', 'max:1000']],
            ['rejection_reason.required' => __('admin.reviews.reason_required')]
        );

        $review->forceFill([
            ProductReview::STATUS => ProductReview::STATUS_REJECTED,
            ProductReview::REJECTION_REASON => $validated['rejection_reason'],
        ])->save();

        return success(new ProductReviewResource($review->refresh()), __('admin.reviews.rejected'));
    }
}
