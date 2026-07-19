<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminTicketReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // فقط ادمین‌ها به این route دسترسی دارند (middleware: role:admin)
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:2', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'message.required' => __('admin.tickets.message_required'),
            'message.max' => __('admin.tickets.message_too_long'),
        ];
    }
}
