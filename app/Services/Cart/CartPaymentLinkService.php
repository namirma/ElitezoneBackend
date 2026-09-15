<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Exceptions\BusinessException;
use App\Jobs\Notification\SendSmsJob;
use App\Models\Cart;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * «الیت‌یار سبد را می‌بندد، کارفرما پولش را می‌دهد» — فاز ۶.
 *
 * تصمیم مدیریت (پرسش ۱، گزینه‌ی ب): لنگرِ لینک، **سبد** است. از بند ۳.۳ به بعد
 * سفارش فقط با پولِ نشسته ساخته می‌شود، پس سفارشِ پرداخت‌نشده‌ای وجود ندارد که
 * بتوان لینکش را فرستاد؛ سبد تنها چیزی است که پیش از پرداخت هست.
 *
 * **لینک هیچ توکنی حمل نمی‌کند و این عمدی است.** کارفرما در هر حال باید وارد
 * حساب شود تا از کیف پولش پرداخت کند، پس احراز هویت خودش گاردِ دسترسی است:
 * `assertPayableBy()` بررسی می‌کند که کاربرِ واردشده همان `beneficiary_user_id`
 * سبد باشد. یک توکنِ امضاشده اینجا چیزی اضافه نمی‌کرد جز یک رازِ قابلِ نشت در
 * متنِ پیامک.
 *
 * اعتبارِ لینک هم ستونِ جداگانه‌ای ندارد: برابرِ `carts.parked_until` است، که
 * `CartParkService` از `reserve_time` تأمین‌کننده مشتق می‌کند — دقیقاً همان
 * چیزی که پاسخ پرسش ۴ خواسته («اعتبار تا Reserve Time»). وقتی سبد منقضی شود،
 * لینک هم با آن می‌میرد و موجودی آزاد می‌شود (پاسخ پرسش ۵).
 */
class CartPaymentLinkService
{
    /**
     * سبد را به کارفرما گره می‌زند و لینک پرداخت را پیامک می‌کند.
     *
     * @return array{link: string, expires_at: ?string, beneficiary_name: string}
     *
     * @throws BusinessException
     */
    public function issue(User $eliteYar, Cart $cart, int $beneficiaryUserId): array
    {
        if ($cart->items()->doesntExist()) {
            throw new BusinessException(__('checkout.cart_is_empty'), 400);
        }

        // کارفرما باید کارفرمای **تأییدشده‌ی همین الیت‌یار** باشد. بدون این،
        // هر کسی می‌توانست سبدی ببندد و صورتحسابش را برای یک کاربر دلخواه
        // بفرستد — همان اشتباهی که `StoreOrderRequest` با قاعده‌ی closure به‌جای
        // `exists:users,id` از آن پرهیز کرده است.
        // larastan نتیجه‌ی رابطه‌ی belongsToMany را `Model&{pivot}` می‌بیند نه `User`.
        /** @var User|null $beneficiary */
        $beneficiary = $eliteYar->associatedEmployers()
            ->where('users.id', $beneficiaryUserId)
            ->wherePivot('status', 'approved')
            ->first();

        if (! $beneficiary) {
            throw new BusinessException(__('orders.store_beneficiary_not_approved_employer'), 422);
        }

        // لینکِ باز برای کارفرمای **دیگری** یعنی تغییرِ سبدِ قفل‌شده — همان
        // قاعده‌ای که روی افزودن/حذفِ آیتم هست. ارسالِ دوباره به همان کارفرما
        // (پیامکِ گم‌شده) مجاز است؛ لغو + صدورِ تازه برای همان نفر فقط یک
        // پیامکِ «لغو سفارش» گیج‌کننده می‌فرستاد.
        if ($cart->isForBeneficiary() && (int) $cart->{Cart::BENEFICIARY_USER_ID} !== $beneficiary->id) {
            $this->assertMutable($cart);
        }

        $cart->forceFill([
            Cart::BENEFICIARY_USER_ID => $beneficiary->id,
            Cart::PAYMENT_LINK_SENT_AT => now(),
            Cart::PAYMENT_LINK_CANCELLED_AT => null,
        ])->save();

        $link = $this->linkFor($cart);

        SendSmsJob::template($beneficiary->mobile, 'cart_payment_link', [
            // ⚠️ `users` هیچ ستونِ `name` ندارد — نام در `user_profiles.name` است.
            // ‏`getAttribute('name')` همیشه null می‌داد و `(string)` آن را به
            // رشته‌ی خالی تبدیل می‌کرد. در پیامک فالبک پنهانش می‌کرد؛ در
            // پاسخِ API فالبکی نبود و فرانت نامِ خالی می‌گرفت.
            'elite_yar_name' => trim((string) $eliteYar->profile?->name) ?: __('global.elite_yar'),
            'link' => $link,
        ]);

        return [
            'link' => $link,
            'expires_at' => $cart->{Cart::PARKED_UNTIL}?->toIso8601String(),
            'beneficiary_name' => (string) $beneficiary->profile?->name,
        ];
    }

    /**
     * لغوِ لینک توسط همان الیت‌یاری که فرستاده (تصمیم مدیریت، گزینه‌ی ب).
     *
     * **بی‌اثر روی سبدی که لینکِ بازی ندارد** — دوبار زدنِ دکمه یا لغوِ سبدی که
     * کارفرما همین حالا پرداخت کرده (سبد با سفارش حذف می‌شود) نباید خطا بدهد؛
     * `false` یعنی «چیزی برای لغو نبود» و هیچ پیامکی نمی‌رود.
     *
     * شرطِ علی (۲۰۲۶-۰۹-۱۴): لغو باید با عنوان «لغو سفارش» برای کارفرما پیامک
     * شود — متنِ `sms.cart_payment_link_cancelled` با همین دو کلمه شروع می‌شود.
     */
    public function cancel(User $eliteYar, Cart $cart): bool
    {
        if (! $cart->isForBeneficiary()) {
            return false;
        }

        $employer = $cart->beneficiary;

        $cart->forceFill([
            Cart::BENEFICIARY_USER_ID => null,
            Cart::PAYMENT_LINK_SENT_AT => null,
            Cart::PAYMENT_LINK_CANCELLED_AT => now(),
        ])->save();

        if ($employer !== null) {
            SendSmsJob::template(
                $employer->mobile,
                'cart_payment_link_cancelled',
                ['elite_yar_name' => trim((string) $eliteYar->profile?->name) ?: __('global.elite_yar')],
            );
        }

        return true;
    }

    /**
     * تا لینکِ پرداخت باز است، سبد قفل است (ممیزی ۲۰۲۶-۰۹-۱۲، بند ۴).
     *
     * الیت‌یار مبلغی را پیامک کرده که کارفرما روی آن تصمیم می‌گیرد؛ هر تغییری
     * در سبد آن عدد را دروغ می‌کند. راهِ درست: اول لغو، بعد تغییر، بعد لینکِ
     * تازه. ۴۰۹ چون تضاد با وضعیتِ فعلیِ منبع است، نه خطای ورودی.
     *
     * @throws BusinessException
     */
    public function assertMutable(Cart $cart): void
    {
        if ($cart->isForBeneficiary()) {
            throw new BusinessException(__('checkout.cart_locked_by_payment_link'), 409);
        }
    }

    /**
     * صفحه‌ی SPA که کارفرما در آن سبد را می‌بیند و پرداخت می‌کند.
     *
     * مسیر باید با روتِ فرانت یکی باشد؛ تستِ sweep
     * (`Navigation/EmittedUrlsMatchSpaRoutesTest`) همین را می‌سنجد.
     */
    public function linkFor(Cart $cart): string
    {
        return rtrim((string) config('app.front_url'), '/').'/checkout/pay-for/'.$cart->id;
    }

    /**
     * آیا این کاربر مجاز است این سبد را بپردازد؟
     *
     * @throws BusinessException
     */
    public function assertPayableBy(Cart $cart, User $employer): void
    {
        if (! $cart->isForBeneficiary()) {
            // «لغو شد» و «لینکی نبود» دو پیامِ متفاوت‌اند: کارفرمایی که پیامکِ
            // لغو گرفته و روی لینکِ قدیمی می‌زند باید همان را ببیند، نه ۴۰۴.
            if ($cart->{Cart::PAYMENT_LINK_CANCELLED_AT} !== null) {
                throw new BusinessException(__('checkout.payment_link_cancelled'), 410);
            }

            throw new BusinessException(__('checkout.cart_has_no_payment_link'), 404);
        }

        if ((int) $cart->{Cart::BENEFICIARY_USER_ID} !== $employer->id) {
            throw new BusinessException(__('global.forbidden'), 403);
        }

        if ($cart->items()->doesntExist()) {
            throw new BusinessException(__('checkout.cart_is_empty'), 400);
        }

        // مهلتِ سبد همان اعتبارِ لینک است. کرونِ `carts:expire-parked` هر دقیقه
        // اجرا می‌شود، ولی بین دو اجرا یک پنجره‌ی کوتاه هست که سبد منقضی است و
        // هنوز حذف نشده — این بررسی همان پنجره را می‌بندد تا کارفرما نتواند
        // روی قیمتی که دیگر معتبر نیست پرداخت کند.
        $deadline = $cart->{Cart::PARKED_UNTIL};

        if ($deadline instanceof Carbon && $deadline->isPast()) {
            throw new BusinessException(__('checkout.payment_link_expired'), 422);
        }
    }
}
