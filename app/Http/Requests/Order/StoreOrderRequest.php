<?php

namespace App\Http\Requests\Order;

use App\DataTransferObjects\Order\PlaceOrderData;
use App\Models\EmployerEliteYAR;
use App\Models\EngineerProfile;
use App\Models\ContractorProfile;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * اعتبارسنجی نقش‌محور ثبت سفارش:
 *
 * مسیر کارفرما (employer):
 *   - elite_yar_user_id: اختیاری؛ باید الیت یار approved+verified خودِ کارفرما باشد
 *   - beneficiary_user_id: ممنوع (کارفرما گیرنده خودش است)
 *
 * مسیر الیت یار (engineer / contractor):
 *   - beneficiary_user_id: اجباری؛ باید کارفرمای approved همین الیت یار باشد
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
        $isEliteYAR = $user?->hasRole('engineer') || $user?->hasRole('contractor');

        if ($isEliteYAR) {
            return $this->eliteYARRules();
        }

        return $this->employerRules();
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
            'description'        => ['nullable', 'string', 'max:1000'],
            'shipping_method_id' => ['required', 'integer', 'exists:shipping_methods,id'],
        ];
    }

    // ── مسیر الیت یار (engineer / contractor) ────────────────────────────────────

    private function eliteYARRules(): array
    {
        return [
            // ممنوع: الیت یار elite_yar_user_id نمی‌فرستد — Action آن را = self ست می‌کند
            'elite_yar_user_id' => [
                'prohibited',
            ],

            // اجباری: کارفرمای approved این الیت یار
            'beneficiary_user_id' => [
                'required',
                'integer',
                'exists:users,id',
                function (string $attribute, $value, Closure $fail): void {
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
            'description'        => ['nullable', 'string', 'max:1000'],
            'shipping_method_id' => ['required', 'integer', 'exists:shipping_methods,id'],
        ];
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /**
     * بررسی تأیید مدارک الیت یار (engineer یا contractor profile).
     */
    private function isEliteYARVerified(User $eliteYAR): bool
    {
        $engineerProfile = EngineerProfile::where(EngineerProfile::USER_ID, $eliteYAR->id)->first();
        if ($engineerProfile) {
            return (bool) $engineerProfile->{EngineerProfile::IS_VERIFIED};
        }

        $contractorProfile = ContractorProfile::where(ContractorProfile::USER_ID, $eliteYAR->id)->first();
        if ($contractorProfile) {
            return (bool) $contractorProfile->{ContractorProfile::IS_VERIFIED};
        }

        return false;
    }

    // ── پیام‌ها و نام‌های نمایشی ─────────────────────────────────────────────

    public function attributes(): array
    {
        return [
            'beneficiary_user_id' => 'کارفرمای گیرنده',
            'elite_yar_user_id'  => 'الیت یار',
            'address_id'          => 'آدرس گیرنده',
            'description'         => 'توضیحات',
            'shipping_method_id'  => 'روش ارسال',
        ];
    }

    public function messages(): array
    {
        return [
            'address_id.required'  => __('orders.store_address_required'),
            'address_id.integer'   => __('orders.store_address_integer'),
            'address_id.exists'    => __('orders.store_address_exists'),

            'shipping_method_id.required' => __('orders.store_shipping_required'),
            'shipping_method_id.integer'  => __('orders.store_shipping_integer'),
            'shipping_method_id.exists'   => __('orders.store_shipping_exists'),

            'beneficiary_user_id.prohibited' => __('orders.store_beneficiary_prohibited_for_employer'),
            'beneficiary_user_id.required'   => __('orders.store_beneficiary_required_for_elite_yar'),
            'beneficiary_user_id.integer'    => __('orders.store_beneficiary_integer'),
            'beneficiary_user_id.exists'     => __('orders.store_beneficiary_exists'),

            'elite_yar_user_id.prohibited' => __('orders.store_elite_yar_prohibited_for_elite_yar'),
            'elite_yar_user_id.integer'    => __('orders.store_elite_yar_integer'),
            'elite_yar_user_id.exists'     => __('orders.store_elite_yar_exists'),

            'description.max' => __('orders.store_description_max'),
        ];
    }

    public function toDto(): PlaceOrderData
    {
        return PlaceOrderData::fromRequest($this);
    }
}
