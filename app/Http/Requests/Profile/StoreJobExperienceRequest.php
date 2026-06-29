<?php

namespace App\Http\Requests\Profile;

use App\Models\JobExperience;
use Illuminate\Foundation\Http\FormRequest;

class StoreJobExperienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Dates are Jalali (e.g. 1402-06-31 — Shahrivar has 31 days). A Gregorian
        // `date_format:Y-m-d` rule rejects valid Jalali dates, so we validate the shape
        // with a regex (same approach as birth_date). end >= start is checked lexically
        // below, which is correct for zero-padded YYYY-MM-DD strings.
        //
        // Range: 1300-01-01 .. today (Jalali). Lexical compare is valid for zero-padded
        // YYYY-MM-DD. `verta()` (no arg) is "now" in Jalali. An ongoing project sends
        // end_date = null (nullable), so the range rule only fires on a present value.
        $inJalaliRange = function ($attribute, $value, $fail) {
            $min = '1300-01-01';
            $today = verta()->format('Y-m-d');
            if ($value < $min || $value > $today) {
                $fail(__('job.date_out_of_range'));
            }
        };

        return [
            JobExperience::TITLE => ['required', 'string', 'max:255'],
            JobExperience::ORGANIZATION_NAME => ['nullable', 'string', 'max:255'],
            JobExperience::PROJECT_ADDRESS => ['nullable', 'string', 'max:500'],
            JobExperience::START_DATE => ['nullable', 'regex:/^\d{4}-\d{2}-\d{2}$/', $inJalaliRange],
            JobExperience::END_DATE => [
                'nullable',
                'regex:/^\d{4}-\d{2}-\d{2}$/',
                $inJalaliRange,
                function ($attribute, $value, $fail) {
                    $start = $this->input(JobExperience::START_DATE);
                    if ($start && $value && $value < $start) {
                        $fail(__('job.end_before_start'));
                    }
                },
            ],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'], // 2MB
        ];
    }

    public function messages(): array
    {
        return [
            JobExperience::TITLE.'.required' => __('job.title_required'),
            'file.required' => __('job.file_required'),
            'file.mimes' => __('job.file_mimes'),
            'file.max' => __('job.file_max'),
        ];
    }
}
