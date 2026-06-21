<?php

namespace App\Http\Requests\Profile;

use App\Models\JobExperience;
use Illuminate\Foundation\Http\FormRequest;

class UpdateJobExperienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Same shape as StoreJobExperienceRequest, but every field is optional so a
     * partial edit is allowed, and `file` is nullable (the user may keep the
     * existing attachment). Jalali dates are validated by regex (see Store request).
     */
    public function rules(): array
    {
        // Same Jalali range as the Store request: 1300-01-01 .. today (lexical compare
        // on zero-padded YYYY-MM-DD). Ongoing project → end_date null (nullable).
        $inJalaliRange = function ($attribute, $value, $fail) {
            $min = '1300-01-01';
            $today = verta()->format('Y-m-d');
            if ($value < $min || $value > $today) {
                $fail(__('job.date_out_of_range'));
            }
        };

        return [
            JobExperience::TITLE => ['sometimes', 'required', 'string', 'max:255'],
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
            'file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'], // 2MB
        ];
    }

    public function messages(): array
    {
        return [
            JobExperience::TITLE.'.required' => __('job.title_required'),
            'file.mimes' => __('job.file_mimes'),
            'file.max' => __('job.file_max'),
        ];
    }
}
