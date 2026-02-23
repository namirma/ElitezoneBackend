<?php
namespace App\Http\Requests;

use App\Models\JobExperience;
use Illuminate\Foundation\Http\FormRequest;

class StoreJobExperienceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            JobExperience::TITLE => ['required', 'string', 'max:255'],
            JobExperience::ORGANIZATION_NAME => ['nullable', 'string', 'max:255'],
            JobExperience::PROJECT_ADDRESS => ['nullable', 'string', 'max:500'],
            JobExperience::START_DATE => ['nullable', 'date_format:Y-m-d'],
            JobExperience::END_DATE => ['nullable', 'date_format:Y-m-d', 'after_or_equal:' . JobExperience::START_DATE],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'], // 2MB
        ];
    }

    public function messages(): array
    {
        return [
            JobExperience::TITLE . '.required' => __('job.title_required'),
            'file.required' => __('job.file_required'),
            'file.mimes' => __('job.file_mimes'),
            'file.max' => __('job.file_max'),
        ];
    }
}
