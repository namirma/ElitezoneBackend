<?php

namespace App\Http\Resources\Product;

use App\Http\Resources\BaseJsonResource;
use App\Models\ProductReview;
use Illuminate\Http\Request;

/**
 * @mixin ProductReview
 */
class ProductReviewResource extends BaseJsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ProductReview::ID => $this->id,
            ProductReview::RATING => $this->rating,
            ProductReview::COMMENT => $this->comment,
            ProductReview::STATUS => $this->status,
            ProductReview::REJECTION_REASON => $this->rejection_reason,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'mobile' => $this->reviewerMobileFor($request),
                'name' => trim(($this->user->profile?->name ?? '').' '.($this->user->profile?->family ?? '')) ?: null,
            ]),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
            ]),
            ProductReview::CREATED_AT => $this->created_at,
            'created_at_p' => $this->created_at_p,
        ];
    }

    /**
     * فهرستِ نظراتِ محصول عمومی است (بدونِ احراز): موبایلِ خامِ نویسنده فقط برای خودش و
     * ادمین، بقیه نسخه‌ی mask‌شده — هم‌الگو با `UserResource`. قبلاً برای هر بازدیدکننده
     * خام برمی‌گشت.
     */
    private function reviewerMobileFor(Request $request): ?string
    {
        $mobile = $this->user->mobile;
        $viewer = $request->user();

        $canSee = $viewer !== null
            && ((int) $viewer->id === (int) $this->{ProductReview::USER_ID} || $viewer->hasRole('admin'));

        return $canSee ? $mobile : $this->maskMobile($mobile);
    }

    private function maskMobile(?string $mobile): ?string
    {
        if ($mobile === null || strlen($mobile) < 7) {
            return $mobile;
        }

        return substr($mobile, 0, 4).str_repeat('*', strlen($mobile) - 7).substr($mobile, -3);
    }
}
