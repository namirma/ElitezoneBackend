<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductReviewRequest;
use App\Http\Resources\Product\ProductReviewResource;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * نظرات محصول — سمت کاربر و عمومی.
 *
 * هر کاربر برای هر محصول یک نظر دارد (unique DB). ارسال مجدد همان رکورد را
 * ویرایش و به صف pending برمی‌گرداند تا دوباره توسط ادمین بررسی شود
 * (AdminReviewController). فقط نظرات approved به صورت عمومی نمایش داده می‌شوند.
 */
class ProductReviewController extends Controller
{
    // GET /products/{product:cuid}/reviews — عمومی، فقط approved
    public function productReviews(Product $product): JsonResponse
    {
        $approved = $product->reviews()
            ->where(ProductReview::STATUS, ProductReview::STATUS_APPROVED);

        $stats = [
            'count' => (clone $approved)->count(),
            'average_rating' => round((float) (clone $approved)->avg(ProductReview::RATING), 1),
        ];

        $reviews = $approved->with('user.profile')
            ->orderByDesc(ProductReview::ID)
            ->paginate($this->perPage());

        return success([
            'stats' => $stats,
            'reviews' => ProductReviewResource::collection($reviews),
        ], __('reviews.list_received'));
    }

    // POST /products/{product:cuid}/reviews — auth:sanctum
    public function store(StoreProductReviewRequest $request, Product $product): JsonResponse
    {
        // فقط خریدار واقعی (placer یا beneficiary سفارش تحویل‌شده حاوی همین محصول)
        // می‌تواند نظر بدهد — «نظر خرید تأییدشده».
        if (! $this->hasDeliveredPurchase($product)) {
            return error(__('reviews.purchase_required'), [], 403);
        }

        $review = ProductReview::firstOrNew([
            ProductReview::USER_ID => Auth::id(),
            ProductReview::PRODUCT_ID => $product->id,
        ]);

        $isResubmission = $review->exists;

        $review->fill($request->validated());

        // هر ثبت/ویرایش دوباره به صف بررسی ادمین برمی‌گردد
        $review->forceFill([
            ProductReview::STATUS => ProductReview::STATUS_PENDING,
            ProductReview::REJECTION_REASON => null,
        ])->save();

        return created(
            new ProductReviewResource($review->refresh()),
            $isResubmission ? __('reviews.updated_pending') : __('reviews.submitted_pending')
        );
    }

    private function hasDeliveredPurchase(Product $product): bool
    {
        return Order::query()
            ->where(fn ($q) => $q
                ->where(Order::PLACER_USER_ID, Auth::id())
                ->orWhere(Order::BENEFICIARY_USER_ID, Auth::id()))
            ->where(Order::STATUS, Order::STATUS_DELIVERED)
            ->whereHas('items.productVariant', fn ($q) => $q
                ->where(ProductVariant::PRODUCT_ID, $product->id))
            ->exists();
    }

    // GET /profile/reviews — نظرات خود کاربر (با وضعیت و دلیل رد)
    public function myReviews(): JsonResponse
    {
        $reviews = ProductReview::query()
            ->where(ProductReview::USER_ID, Auth::id())
            ->with('product')
            ->orderByDesc(ProductReview::ID)
            ->paginate($this->perPage());

        return success(ProductReviewResource::collection($reviews), __('reviews.my_list_received'));
    }
}
