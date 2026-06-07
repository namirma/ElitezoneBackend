<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminTicketStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:open,answered,pending,closed'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => __('admin.tickets.status_required'),
            'status.in'       => __('admin.tickets.status_invalid'),
        ];
    }
}
