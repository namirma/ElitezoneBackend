<?php

namespace App\Http\Resources\Marketing;

use App\Http\Resources\BaseJsonResource;
use App\Models\Coupon;
use Illuminate\Http\Request;

/**
 * @mixin Coupon
 */
class CouponResource extends BaseJsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // تأمینِ تخفیف (docs/specs/COUPON_FUNDING_2026-09-15.md): وضعیتِ کلی از
        // ردیف‌ها مشتق می‌شود؛ کوپنِ بی‌ردیف «شرکت ۱۰۰٪» است و همان را نشان می‌دهد.
        $fundingStatus = $this->fundingStatus();

        return [
            Coupon::ID => $this->id,
            Coupon::CODE => $this->code,
            Coupon::TYPE => $this->type,
            Coupon::AMOUNT => $this->amount,
            Coupon::USER_ID => $this->user_id,
            Coupon::EXPIRES_AT => $this->expires_at,
            Coupon::USED_AT => $this->used_at,
            // کمپین (پرسشِ ۱۰ مدیریت): بودجه‌ی کل، مصرف‌شده و باقی‌مانده؛ غیرکمپینی ⇒ null.
            'is_campaign' => $this->isCampaign(),
            // دورِ ششم، پرسشِ ۴: «چندبارمصرف» صریح؛ بودجه دیگر سقف نیست مگر `budget_cap_enforced`.
            Coupon::IS_MULTI_USE => $this->isCampaign(),
            'budget_cap_enforced' => $this->hasBudgetCap(),
            Coupon::BUDGET_TOTAL => $this->isCampaign() ? (string) $this->{Coupon::BUDGET_TOTAL} : null,
            Coupon::BUDGET_USED => $this->isCampaign() ? (string) ($this->{Coupon::BUDGET_USED} ?? '0') : null,
            'budget_remaining' => $this->isCampaign() ? (string) $this->budgetRemaining() : null,
            Coupon::REASON => $this->reason,
            Coupon::IS_ACTIVE => $this->is_active,
            'is_valid' => $this->isValid() && $fundingStatus === 'ready',
            Coupon::CREATED_AT => $this->created_at,
            'created_at_p' => $this->created_at_p,
            Coupon::CREATED_BY_USER_ID => $this->created_by_user_id,
            'creator_name' => $this->whenLoaded('creator', fn () => $this->creator
                ? ($this->creator->profile ? $this->creator->full_name : $this->creator->mobile)
                : null),
            'funders' => CouponFunderResource::collection($this->fundingRows()),
            'funding_status' => $fundingStatus,
            'funding_status_info' => [
                'key' => $fundingStatus,
                'label' => trans('coupons.funding_statuses')[$fundingStatus] ?? $fundingStatus,
                'tone' => match ($fundingStatus) {
                    'ready' => 'success',
                    'declined' => 'failure',
                    default => 'ongoing',
                },
            ],
        ];
    }
}
