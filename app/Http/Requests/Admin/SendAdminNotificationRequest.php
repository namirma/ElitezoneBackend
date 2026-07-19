<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ارسال اعلان از پنل ادمین — یا به همه کاربران (all=true) یا به لیست user_ids.
 */
class SendAdminNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'all' => ['nullable', 'boolean'],
            'user_ids' => ['required_without:all', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'subject.required' => __('admin.notifications.subject_required'),
            'body.required' => __('admin.notifications.body_required'),
            'user_ids.required_without' => __('admin.notifications.target_required'),
            'user_ids.*.exists' => __('admin.notifications.user_not_found'),
        ];
    }
}
