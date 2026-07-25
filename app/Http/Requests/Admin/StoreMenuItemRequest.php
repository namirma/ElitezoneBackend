<?php

namespace App\Http\Requests\Admin;

use App\Models\MenuItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * ساخت آیتم منوی سایت توسط ادمین.
 *
 * دو قید مهم که در rules ساده نمی‌گنجند و در withValidator اعمال می‌شوند:
 *   ۱) درخت هرگز از سه سطح عمیق‌تر نشود (طراحیِ مگامنو بیش از این را رندر نمی‌کند)
 *   ۲) url یا مسیر داخلیِ با / شروع‌شونده باشد یا آدرس کامل http(s)
 */
class StoreMenuItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            MenuItem::PARENT_ID => ['nullable', 'integer', 'exists:menu_items,id'],
            MenuItem::LOCATION => ['nullable', 'string', Rule::in(MenuItem::LOCATIONS)],
            MenuItem::TITLE => ['required', 'string', 'max:150'],
            MenuItem::URL => ['nullable', 'string', 'max:500'],
            MenuItem::IS_HIGHLIGHTED => ['nullable', 'boolean'],
            MenuItem::SORT_ORDER => ['nullable', 'integer', 'min:0'],
            MenuItem::IS_ACTIVE => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $this->validateDepth($v, (int) $this->input(MenuItem::PARENT_ID));
            $this->validateUrl($v, $this->input(MenuItem::URL));
        });
    }

    protected function validateDepth(Validator $v, ?int $parentId): void
    {
        if (! $parentId) {
            return;
        }

        $parent = MenuItem::find($parentId);
        if ($parent && $parent->depth() >= MenuItem::MAX_DEPTH) {
            $v->errors()->add(MenuItem::PARENT_ID, __('admin.menu.max_depth'));
        }
    }

    protected function validateUrl(Validator $v, ?string $url): void
    {
        if ($url === null || $url === '') {
            return;
        }

        $isInternal = str_starts_with($url, '/');
        $isAbsolute = (bool) filter_var($url, FILTER_VALIDATE_URL);

        if (! $isInternal && ! $isAbsolute) {
            $v->errors()->add(MenuItem::URL, __('admin.menu.url_invalid'));
        }
    }

    public function messages(): array
    {
        return [
            'title.required' => __('admin.menu.title_required'),
        ];
    }
}
