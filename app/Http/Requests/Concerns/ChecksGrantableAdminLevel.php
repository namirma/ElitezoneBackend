<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;
use Closure;

/**
 * سقفِ اعطای سطحِ اختیار — مشترکِ ساخت و ویرایشِ مدیر.
 *
 * هیچ‌کس سطحی بالاتر از سطحِ خودش به دیگری نمی‌دهد (`User::grantableAdminLevels`).
 * جدا از `Rule::in(User::ADMIN_LEVELS)` نوشته شده تا `api.json` هر سه سطح را مستند
 * کند، نه فهرستی که به بیننده بستگی دارد. پین: `Admin/AdminManagerLevelTest`.
 */
trait ChecksGrantableAdminLevel
{
    private function withinGrantableLevels(string $attribute, mixed $value, Closure $fail): void
    {
        $granter = $this->user();

        if (! $granter instanceof User || ! in_array((int) $value, $granter->grantableAdminLevels(), true)) {
            $fail(__('admin.levels.invalid'));
        }
    }
}
