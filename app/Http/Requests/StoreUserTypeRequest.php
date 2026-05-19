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
            'roles' => 'required|array|min:1',
            'roles.*' => 'in:employer,supplier,expert',
            'employer_has_consultant' => 'nullable|boolean',
            'type_id' => 'nullable|integer|exists:user_types,id',
        ];
    }

    public function messages(): array
    {
        return [
            'roles.required' => '.انتخاب حداقل یک نقش الزامی است',
            'roles.min' => '.انتخاب حداقل یک نقش الزامی است',
            'roles.*.in' => 'نقش نامعتبر است. نقش‌های مجاز: employer, supplier, expert',
            'roles.array' => '.باید آرایه باشد roles فیلد ',
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

            if (
                in_array('supplier', $roles, true) &&
                count(array_intersect($roles, ['employer', 'expert'])) > 0
            ) {
                $v->errors()->add('roles', '.نقش سوپلایر نمیتواند همزمان با کارفرما یا مشاورفنی انتخاب شود');
            }

            if (empty($roles)) {
                $v->errors()->add('roles', '.انتخاب حداقل یک نقش معتبر الزامی است');
            }
        });
    }
}
