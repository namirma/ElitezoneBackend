<?php

namespace App\Http\Requests\Admin;

use App\Models\MenuItem;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * ویرایش آیتم منوی سایت.
 *
 * علاوه بر قیدهای ساخت (عمق ≤ ۳ و فرمت url)، یک قید حیاتیِ اضافه دارد:
 * آیتم نباید زیرمجموعه‌ی خودش شود (حلقه در درخت) — وگرنه رندر منو در فرانت
 * بی‌نهایت بازگشتی می‌شود.
 */
class UpdateMenuItemRequest extends StoreMenuItemRequest
{
    public function rules(): array
    {
        return [
            MenuItem::PARENT_ID => ['nullable', 'integer', 'exists:menu_items,id'],
            MenuItem::LOCATION => ['nullable', 'string', Rule::in(MenuItem::LOCATIONS)],
            MenuItem::TITLE => ['sometimes', 'required', 'string', 'max:150'],
            MenuItem::URL => ['nullable', 'string', 'max:500'],
            MenuItem::IS_HIGHLIGHTED => ['nullable', 'boolean'],
            MenuItem::SORT_ORDER => ['nullable', 'integer', 'min:0'],
            MenuItem::IS_ACTIVE => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $parentId = $this->input(MenuItem::PARENT_ID);
            $parentId = $parentId === null || $parentId === '' ? null : (int) $parentId;

            $this->validateDepth($v, $parentId);
            $this->validateUrl($v, $this->input(MenuItem::URL));
            $this->validateNoCycle($v, $parentId);
        });
    }

    /** والدِ جدید نباید خودِ آیتم یا یکی از فرزندانش باشد. */
    protected function validateNoCycle(Validator $v, ?int $parentId): void
    {
        // نامِ پارامتر مسیر در routes/api/admin.php دقیقاً `menuItem` است؛
        // با `menu_item` مقدار null برمی‌گشت و این اعتبارسنجی بی‌صدا اجرا نمی‌شد.
        $item = $this->route('menuItem');
        if (! $item instanceof MenuItem || $parentId === null) {
            return;
        }

        if ($parentId === (int) $item->getKey()) {
            $v->errors()->add(MenuItem::PARENT_ID, __('admin.menu.self_parent'));

            return;
        }

        // بالا رفتن از والدِ پیشنهادی: اگر به خودمان رسیدیم، حلقه ساخته می‌شود
        $cursor = MenuItem::find($parentId);
        $guard = 0;
        while ($cursor !== null && $guard++ < 10) {
            if ((int) $cursor->getKey() === (int) $item->getKey()) {
                $v->errors()->add(MenuItem::PARENT_ID, __('admin.menu.cyclic_parent'));

                return;
            }
            $cursor = $cursor->parent;
        }
    }
}
