<?php

namespace App\Http\Requests\Product;

use App\Models\ProductReview;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ثبت نظر محصول توسط کاربر لاگین‌شده.
 *
 * authorize() همیشه true است چون روت پشت auth:sanctum قرار دارد؛ محصول از
 * route-model-binding ({product:cuid}) می‌آید.
 */
class StoreProductReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ProductReview::RATING => ['required', 'integer', 'min:1', 'max:5'],
            ProductReview::COMMENT => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'rating.required' => __('reviews.rating_required'),
            'rating.integer' => __('reviews.rating_invalid'),
            'rating.min' => __('reviews.rating_invalid'),
            'rating.max' => __('reviews.rating_invalid'),
            'comment.max' => __('reviews.comment_too_long'),
        ];
    }
}
