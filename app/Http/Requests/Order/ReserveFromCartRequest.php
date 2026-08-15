<?php

namespace App\Http\Requests\Order;

/**
 * رزرو از روی سبد — شاخه‌ی ج (بند ۳.۳) + الحاقیه‌ی ۱، تغییر ۲.
 *
 * همان فیلدهای `StoreOrderRequest` را می‌گیرد، به‌علاوه‌ی `confirm_short_window`.
 *
 * چرا `confirm_short_window` سمتِ سرور الزامی است و نه فقط یک چک‌باکسِ فرانت:
 * اگر هشدارِ «وقت کم» فقط در کلاینت باشد، فراموش‌شدنش هیچ ردی نمی‌گذارد و
 * خریدار روی رزروی که چند دقیقه بعد منقضی می‌شود پول می‌گذارد. الزامِ صریح،
 * آن تعهد را قابلِ‌تأیید می‌کند. قاعده‌ی «کِی الزامی است» در کنترلر است، چون
 * به `reserve_time` تأمین‌کننده‌ی همین سبد بستگی دارد.
 */
class ReserveFromCartRequest extends StoreOrderRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'confirm_short_window' => ['sometimes', 'boolean'],
        ]);
    }
}
