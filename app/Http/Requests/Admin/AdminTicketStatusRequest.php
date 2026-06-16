<?php

namespace App\Http\Requests\Admin;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminTicketStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(Ticket::STATUSES)],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => __('admin.tickets.status_required'),
            'status.in' => __('admin.tickets.status_invalid'),
        ];
    }
}
