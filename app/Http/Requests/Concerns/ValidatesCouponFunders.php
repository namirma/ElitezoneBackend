<?php

namespace App\Http\Requests\Concerns;

use App\Models\CouponFunder;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * قواعدِ مشترکِ `funders[]` برای ساخت/ویرایشِ کوپن — ادمین، تأمین‌کننده، الیت‌یار
 * (docs/specs/COUPON_FUNDING_2026-09-15.md §۳.۱).
 *
 * هر عضو: `funder_type`، `share_percent`، و برای غیرِ شرکت یکی از
 * `funder_user_id` یا `funder_mobile` (شماره همین‌جا به کاربر تبدیل می‌شود).
 * جمعِ درصدها دقیقاً ۱۰۰، بدونِ ردیفِ تکراری، کاربرِ `supplier` تأمین‌کننده‌ی
 * تأییدشده و کاربرِ `elite_yar` الیت‌یار. همه ۴۲۲ با پیامِ فارسی — هرگز ۵۰۰.
 */
trait ValidatesCouponFunders
{
    /** @var list<array{funder_type: string, funder_user_id: int|null, share_percent: float}> */
    private array $resolvedFunders = [];

    /** @return array<string, array<int, mixed>> */
    protected function funderRules(bool $required): array
    {
        return [
            'funders' => [$required ? 'required' : 'sometimes', 'array', 'min:1'],
            'funders.*.funder_type' => ['required', Rule::in(CouponFunder::TYPES)],
            'funders.*.funder_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'funders.*.funder_mobile' => ['nullable', 'string', 'max:20'],
            'funders.*.share_percent' => ['required', 'numeric', 'min:0.01', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    protected function funderMessages(): array
    {
        return [
            'funders.required' => __('coupons.funders_required'),
            'funders.min' => __('coupons.funders_required'),
            'funders.*.funder_type.required' => __('coupons.funder_type_invalid'),
            'funders.*.funder_type.in' => __('coupons.funder_type_invalid'),
            'funders.*.funder_user_id.exists' => __('coupons.funder_user_not_found'),
            'funders.*.share_percent.required' => __('coupons.share_percent_invalid'),
            'funders.*.share_percent.numeric' => __('coupons.share_percent_invalid'),
            'funders.*.share_percent.min' => __('coupons.share_percent_invalid'),
            'funders.*.share_percent.max' => __('coupons.share_percent_invalid'),
        ];
    }

    /**
     * بررسی‌های میان‌ردیفی. `$selfType` + `$selfId`: نوع و شناسه‌ی سازنده‌ی غیرِادمین —
     * ردیفِ همان نوع بدونِ کاربر به خودِ او نسبت داده می‌شود و باید حضور داشته باشد.
     */
    protected function validateFunders(Validator $validator, ?string $selfType = null, ?int $selfId = null): void
    {
        $validator->after(function (Validator $v) use ($selfType, $selfId) {
            $funders = $this->input('funders');

            // خطای هر ردیف را قاعده‌های پایه از قبل داده‌اند؛ این‌جا فقط بررسی‌های
            // میان‌ردیفی می‌ماند. مقدارِ غیراسکالر (کلاینتی که آرایه فرستاده) به
            // پیش‌فرض می‌افتد تا هشدارِ «Array to string» به ۵۰۰ تبدیل نشود.
            if (! is_array($funders) || $v->errors()->isNotEmpty()) {
                return;
            }

            // مدیریت، دورِ ششم، پرسشِ ۱: فقط شرکت کوپن صادر می‌کند — سهمِ تأمین‌کننده/الیت‌یار پذیرفته نیست.
            if (! config('features.partner_coupons') && collect($funders)->contains(
                fn ($row) => ! is_array($row) || ($row['funder_type'] ?? null) !== CouponFunder::TYPE_COMPANY
            )) {
                $v->errors()->add('funders', __('coupons.partner_coupons_off'));

                return;
            }

            $scalar = fn ($value, string $default = ''): string => is_scalar($value) ? (string) $value : $default;

            $resolved = [];
            $seen = [];
            $sum = 0.0;

            foreach (array_values($funders) as $i => $row) {
                $row = is_array($row) ? $row : [];
                $type = $scalar($row['funder_type'] ?? null);
                $rawUserId = $scalar($row['funder_user_id'] ?? null);
                $userId = $rawUserId !== '' ? (int) $rawUserId : null;
                $mobile = trim($scalar($row['funder_mobile'] ?? null));
                $percent = round((float) $scalar($row['share_percent'] ?? null, '0'), 2);

                if ($type === CouponFunder::TYPE_COMPANY) {
                    if ($userId !== null || $mobile !== '') {
                        $v->errors()->add("funders.{$i}.funder_user_id", __('coupons.funder_company_has_no_user'));

                        continue;
                    }
                } else {
                    if ($userId === null && $mobile === '' && $selfType === $type && $selfId !== null) {
                        $userId = $selfId;
                    }

                    if ($userId === null && $mobile !== '') {
                        $userId = User::where(User::MOBILE, PhoneNumber::to09($mobile))->value('id');

                        if ($userId === null) {
                            $v->errors()->add("funders.{$i}.funder_mobile", __('coupons.funder_user_not_found'));

                            continue;
                        }
                        $userId = (int) $userId;
                    }

                    if ($userId === null) {
                        $v->errors()->add("funders.{$i}.funder_user_id", __('coupons.funder_user_required'));

                        continue;
                    }

                    $user = User::find($userId);

                    if ($type === CouponFunder::TYPE_SUPPLIER) {
                        $approved = $user !== null
                            && $user->hasRole('supplier')
                            && SupplierProfile::where(SupplierProfile::USER_ID, $userId)
                                ->where(SupplierProfile::STATUS, SupplierProfile::STATUS_APPROVED)
                                ->exists();

                        if (! $approved) {
                            $v->errors()->add("funders.{$i}.funder_user_id", __('coupons.funder_not_supplier'));

                            continue;
                        }
                    } elseif ($user === null || ! ($user->hasRole('elite_yar') || $user->isEliteYAR())) {
                        // همان دو واژگانِ `EnsureUserIsEliteYAR` (نقشِ نمایشیِ `elite_yar`) و
                        // `User::isEliteYAR()` (نقش‌های فنیِ engineer/contractor).
                        $v->errors()->add("funders.{$i}.funder_user_id", __('coupons.funder_not_elite_yar'));

                        continue;
                    }
                }

                $key = $type.':'.($userId ?? '');

                if (isset($seen[$key])) {
                    $v->errors()->add("funders.{$i}.funder_type", __('coupons.funders_duplicate'));

                    continue;
                }

                $seen[$key] = true;
                $sum += $percent;
                $resolved[] = ['funder_type' => $type, 'funder_user_id' => $userId, 'share_percent' => $percent];
            }

            if ($v->errors()->isNotEmpty()) {
                return;
            }

            if (abs($sum - 100.0) > 0.001) {
                $v->errors()->add('funders', __('coupons.funders_sum'));
            }

            if ($selfType !== null && $selfId !== null) {
                $self = collect($resolved)->first(
                    fn ($f) => $f['funder_type'] === $selfType && $f['funder_user_id'] === $selfId && $f['share_percent'] > 0
                );

                if ($self === null) {
                    $v->errors()->add('funders', __('coupons.creator_must_fund'));
                }
            }

            $this->resolvedFunders = $resolved;
        });
    }

    /**
     * ردیف‌های نرمال‌شده (شماره‌ها به شناسه تبدیل شده). فقط بعد از اعتبارسنجیِ موفق.
     *
     * @return list<array{funder_type: string, funder_user_id: int|null, share_percent: float}>
     */
    public function normalizedFunders(): array
    {
        return $this->resolvedFunders;
    }

    public function hasFunders(): bool
    {
        return is_array($this->input('funders'));
    }
}
