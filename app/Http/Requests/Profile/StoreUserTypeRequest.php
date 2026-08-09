<?php

namespace App\Http\Requests\Profile;

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
            'roles.*' => 'in:employer,supplier,elite_yar',
            'employer_has_elite_yar' => 'nullable|boolean',
            'type_id' => 'nullable|integer|exists:user_types,id',
            // پذیرش توافق‌نامه‌ها — یا terms_accepted=true (پذیرش یکجا) یا
            // فهرست کد چک‌باکس‌های تیک‌خورده (CHK-01..08 برای الیت یار)
            'terms_accepted' => 'nullable|boolean',
            'accepted_checkboxes' => 'nullable|array',
            'accepted_checkboxes.*' => 'string|max:20',
        ];
    }

    public function messages(): array
    {
        return [
            'roles.required' => __('profile.roles_required'),
            'roles.min' => __('profile.roles_required'),
            'roles.*.in' => __('profile.roles_invalid'),
            'roles.array' => __('profile.roles_array'),
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
                count(array_intersect($roles, ['employer', 'elite_yar'])) > 0
            ) {
                $v->errors()->add('roles', __('profile.roles_supplier_conflict'));
            }

            if (empty($roles)) {
                $v->errors()->add('roles', __('profile.roles_required'));
            }
        });
    }
}
