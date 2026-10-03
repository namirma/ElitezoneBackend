<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\Profile\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * یک مدیر از نگاهِ صفحه‌ی «مدیران» — همان `UserResource` به‌علاوه‌ی سطحِ اختیار.
 *
 * `UserResource` سطح را فقط برای خودِ ادمین روی پروفایلِ خودش بیرون می‌دهد. این‌جا
 * بیننده همیشه مدیر ارشد است (گروهِ مدیران `admin.level:1` است) و بدونِ سطحِ هر ردیف
 * پنل نه فرمِ ویرایش را پیش‌پر می‌کند و نه ادمینِ بی‌سطح را نشان می‌دهد. بی‌سطح
 * `null` برمی‌گردد، نه کلیدِ غایب.
 *
 * @mixin User
 */
class AdminManagerResource extends UserResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            User::ADMIN_LEVEL => $this->{User::ADMIN_LEVEL} !== null ? (int) $this->{User::ADMIN_LEVEL} : null,
        ];
    }
}
