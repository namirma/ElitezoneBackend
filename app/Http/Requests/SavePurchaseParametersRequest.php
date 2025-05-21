<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SavePurchaseParametersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'parameters'   => ['required', 'array'],
            'parameters.*' => ['integer', 'exists:purchase_parameter_values,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $values = \App\Models\PurchaseParameterValue::whereIn('id', $this->parameters)->get();
            $dupGrp = $values->groupBy('group_id')->first(fn($g) => $g->count() > 1);

            if ($dupGrp) {
                $v->errors()->add('parameters', 'برای هر گروه فقط یک گزینه مجاز است.');
            }
        });
    }
}
