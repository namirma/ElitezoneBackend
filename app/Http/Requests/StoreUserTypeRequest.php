<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'roles'                   => 'required_without:type_id|array|min:1',
            'roles.*'                 => 'in:engineer,contractor,employer,supplier',
            'employer_has_consultant' => 'nullable|boolean',
            'type_id'                 => 'nullable|integer|exists:user_types,id',
        ];
    }

    public function messages(): array
    {
        return [
            'roles.required_without' => 'وقتی type_id نمی‌فرستید، فیلد roles الزامی است.',
            'roles.*.in'             => 'نقش نامعتبر است. نقش‌های مجاز: engineer, contractor, employer, supplier',
            'roles.array'            => 'فیلد roles باید آرایه باشد.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $raw = $this->input('roles');
        if (is_string($raw)) {
            $roles = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        } elseif (is_array($raw)) {
            $roles = [];
            foreach ($raw as $item) {
                if (is_string($item)) {
                    $roles = array_merge($roles, preg_split('/\s*,\s*/', $item, -1, PREG_SPLIT_NO_EMPTY));
                }
            }
        } else {
            $roles = [];
        }

        $roles = array_values(array_unique(array_map(
            fn ($v) => strtolower(trim($v)),
            array_filter($roles, fn ($v) => $v !== '')
        )));

        $this->merge(['roles' => $roles]);
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            $roles = $this->input('roles', []);
            if (in_array('supplier', $roles, true) &&
                count(array_intersect($roles, ['engineer','contractor','employer'])) > 0) {
                $v->errors()->add('roles', 'نقش supplier نمی‌تواند همزمان با engineer/contractor/employer انتخاب شود.');
            }
        });
    }
}
