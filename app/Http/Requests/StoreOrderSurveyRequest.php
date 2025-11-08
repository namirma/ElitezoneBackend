<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // ما منطق دسترسی را در کنترلر چک می‌کنیم
        return true;
    }

    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
