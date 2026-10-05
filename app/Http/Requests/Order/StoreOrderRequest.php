<?php

namespace App\Http\Requests\Order;

use App\DataTransferObjects\Order\PlaceOrderData;
use App\Models\EmployerEliteYAR;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * اعتبارسنجی نقش‌محور ثبت سفارش:
 *
 * مسیر کارفرما (employer):
 *   - elite_yar_user_id: اختیاری؛ باید الیت یار approved+verified خودِ کارفرما باشد.
 *     پرسشِ ۸ مدیریت (۱۴۰۵/۰۷/۱۳): با **یک** الیت‌یارِ تأییدشده خودکار روی سفارش
 *     می‌نشیند (PlaceOrderAction)؛ با **چند** تا باید یکی انتخاب شود — این‌جا ۴۲۲.
 *   - beneficiary_user_id: ممنوع (کارفرما گیرنده خودش است)
 *
 * مسیر الیت یار (`elite_yar`، و نقش‌های قدیمیِ engineer / contractor — پرسشِ ۱):
 *   - beneficiary_user_id: اختیاری — خالی یعنی خریدِ خودِ الیت‌یار (پرسشِ ۱: الیت‌یارِ
 *     تأییدشده روی خریدِ خودش قیمتِ با تخفیف می‌بیند)؛ در صورتِ ارسال باید کارفرمای
 *     approved همین الیت یار باشد و الیت‌یار «هولد» نباشد.
 *   - elite_yar_user_id: ممنوع در request؛ توسط PlaceOrderAction = self ست می‌شود
 */
class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = Auth::user();
        $isEliteYAR = (bool) $user?->isEliteYAR();

        $rules = $isEliteYAR ? $this->eliteYARRules() : $this->employerRules();

        // کد تخفیف — اعتبارسنجی محتوایی (فعال/منقضی/مصرف‌شده/شخصی) در
        // CouponService::validateForCheckout انجام می‌شود تا پیام دقیق برگردد.
        $rules['coupon_code'] = ['nullable', 'string', 'max:100'];

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $user = Auth::user();
            if (! $user instanceof User) {
                return;
            }

            if ($user->isEliteYAR()) {
                // الیت‌یارِ هولد (بی‌لایسنسِ سازه ۹۰) برای خودش می‌خرد، نه برای کارفرما —
                // سهم و تخفیفِ الیت‌یاری فقط با حکمِ تأیید معنا دارد (پرسشِ ۱، قیدِ سوم).
                if ($this->filled('beneficiary_user_id') && ! $user->isVerifiedEliteYAR()) {
                    $v->errors()->add('beneficiary_user_id', __('orders.store_elite_yar_on_hold'));
                }

                return;
            }

            // کارفرما با چند الیت‌یارِ تأییدشده باید یکی را انتخاب کند (پرسشِ ۸، گزینه‌ی الف).
            if (! $this->filled('elite_yar_user_id') && $user->verifiedEliteYARs()->count() > 1) {
                $v->errors()->add('elite_yar_user_id', __('orders.store_elite_yar_choice_required'));
            }
        });
    }

    // ── مسیر کارفرما ──────────────────────────────────────────────────────────

    private function employerRules(): array
    {
        return [
            // ممنوع: کارفرما beneficiary_user_id نمی‌تواند بفرستد
            'beneficiary_user_id' => [
                'prohibited',
            ],

            // اختیاری: الیت یار باید approved+verified باشد
            'elite_yar_user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
                function (string $attribute, $value, Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    $employer = Auth::user();

                    // بررسی ۱: آیا الیت یار در لیست الیت یارها تأییدشده این کارفرما هست؟
                    $isApproved = $employer
                        ->technicalEliteYARs()
                        ->where('users.id', $value)
                        ->wherePivot(EmployerEliteYAR::STATUS, EmployerEliteYAR::STATUS_APPROVED)
                        ->exists();

                    if (! $isApproved) {
                        $fail(__('orders.store_elite_yar_not_approved'));

                        return;
                    }

                    // بررسی ۲: آیا الیت یار گواهی تأیید مدارک دارد (is_verified = true)؟
                    $eliteYAR = User::find($value);
                    if (! $eliteYAR) {
                        $fail(__('orders.store_elite_yar_not_found'));

                        return;
                    }

                    $isVerified = $this->isEliteYARVerified($eliteYAR);
                    if (! $isVerified) {
                        $fail(__('orders.store_elite_yar_not_verified'));
                    }
                },
            ],

            'address_id' => [
                'required',
                'integer',
                Rule::exists('addresses', 'id')->where('user_id', Auth::id()),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'shipping_method_id' => ['required', 'integer', 'exists:shipping_methods,id'],
        ];
    }

    // ── مسیر الیت یار (elite_yar / engineer / contractor) ─────────────────────────

    private function eliteYARRules(): array
    {
        return [
            // ممنوع: الیت یار elite_yar_user_id نمی‌فرستد — Action آن را = self ست می‌کند
            'elite_yar_user_id' => [
                'prohibited',
            ],

            // اختیاری: کارفرمای approved این الیت یار — خالی یعنی خریدِ خودِ الیت‌یار.
            'beneficiary_user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
                function (string $attribute, $value, Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    $eliteYAR = Auth::user();

                    $isApprovedEmployer = $eliteYAR
                        ->associatedEmployers()
                        ->where('users.id', $value)
                        ->wherePivot(EmployerEliteYAR::STATUS, EmployerEliteYAR::STATUS_APPROVED)
                        ->exists();

                    if (! $isApprovedEmployer) {
                        $fail(__('orders.store_beneficiary_not_approved_employer'));
                    }
                },
            ],

            'address_id' => [
                'required',
                'integer',
                Rule::exists('addresses', 'id')->where('user_id', Auth::id()),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'shipping_method_id' => ['required', 'integer', 'exists:shipping_methods,id'],
        ];
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /**
     * تأییدِ الیت یار — همان محمولِ یگانه‌ی مدل. قبلاً نسخه‌ی خودش را داشت که
     * سازه ۹۰ را نمی‌شمرد و عضوِ سازه ۹۰ را روی سفارش ۴۲۲ می‌کرد.
     */
    private function isEliteYARVerified(User $eliteYAR): bool
    {
        return $eliteYAR->isVerifiedEliteYAR();
    }

    // ── پیام‌ها و نام‌های نمایشی ─────────────────────────────────────────────

    public function attributes(): array
    {
        return [
            'beneficiary_user_id' => 'کارفرمای گیرنده',
            'elite_yar_user_id' => 'الیت یار',
            'address_id' => 'آدرس گیرنده',
            'description' => 'توضیحات',
            'shipping_method_id' => 'روش ارسال',
        ];
    }

    public function messages(): array
    {
        return [
            'address_id.required' => __('orders.store_address_required'),
            'address_id.integer' => __('orders.store_address_integer'),
            'address_id.exists' => __('orders.store_address_exists'),

            'shipping_method_id.required' => __('orders.store_shipping_required'),
            'shipping_method_id.integer' => __('orders.store_shipping_integer'),
            'shipping_method_id.exists' => __('orders.store_shipping_exists'),

            'beneficiary_user_id.prohibited' => __('orders.store_beneficiary_prohibited_for_employer'),
            'beneficiary_user_id.required' => __('orders.store_beneficiary_required_for_elite_yar'),
            'beneficiary_user_id.integer' => __('orders.store_beneficiary_integer'),
            'beneficiary_user_id.exists' => __('orders.store_beneficiary_exists'),

            'elite_yar_user_id.prohibited' => __('orders.store_elite_yar_prohibited_for_elite_yar'),
            'elite_yar_user_id.integer' => __('orders.store_elite_yar_integer'),
            'elite_yar_user_id.exists' => __('orders.store_elite_yar_exists'),

            'description.max' => __('orders.store_description_max'),
        ];
    }

    public function toDto(): PlaceOrderData
    {
        return PlaceOrderData::fromRequest($this);
    }
}
