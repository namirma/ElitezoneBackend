<?php

namespace App\Http\Requests\Profile;

use App\Models\SupplierProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;

/**
 * تعیین `reserve_time` توسط خودِ تأمین‌کننده — بند ۷.۳ و گاردهای بند ۹.
 *
 * دو قاعده اینجا اعمال می‌شوند:
 *   ۱. باید در **آینده** باشد — تاریخِ گذشته یعنی محصولات همان لحظه از فروش
 *      خارج می‌شوند، که هیچ‌وقت خواسته‌ی کاربر نیست و فقط یک اشتباهِ تایپی است.
 *   ۲. **کاهش ممنوع.** خریدارانی که همین حالا سبدِ منتظر دارند مهلتشان را از
 *      این عدد گرفته‌اند (بند ۳.۱)؛ جلو کشیدنش یعنی سبدِ کسی زودتر از آنچه به
 *      او گفته شده منقضی شود. تمدید آزاد است، کوتاه‌کردن نه.
 */
class UpdateSupplierReserveTimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            // ISO-8601 / `Y-m-d H:i:s` — این یک تاریخِ **میلادیِ** واقعی است
            // (لحظه‌ی مطلق)، نه تاریخِ جلالیِ نمایشی، پس قاعده‌ی regexِ جلالیِ
            // پروژه اینجا موضوعیت ندارد.
            'reserve_time' => ['required', 'date'],
            'reserve_auto_extend_minutes' => ['sometimes', 'integer', 'min:0', 'max:10080'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $new = $this->date('reserve_time');

            if ($new === null) {
                return;
            }

            if ($new->isPast()) {
                $validator->errors()->add('reserve_time', __('supplier.reserve_time.must_be_future'));

                return;
            }

            $current = SupplierProfile::where(SupplierProfile::USER_ID, Auth::id())
                ->value(SupplierProfile::RESERVE_TIME);

            if ($current !== null && $new->lessThan($current)) {
                $validator->errors()->add('reserve_time', __('supplier.reserve_time.cannot_decrease'));
            }
        });
    }

    public function messages(): array
    {
        return [
            'reserve_time.required' => __('supplier.reserve_time.required'),
            'reserve_time.date' => __('supplier.reserve_time.invalid'),
            'reserve_auto_extend_minutes.integer' => __('supplier.reserve_time.extend_invalid'),
            'reserve_auto_extend_minutes.min' => __('supplier.reserve_time.extend_invalid'),
            'reserve_auto_extend_minutes.max' => __('supplier.reserve_time.extend_invalid'),
        ];
    }
}
