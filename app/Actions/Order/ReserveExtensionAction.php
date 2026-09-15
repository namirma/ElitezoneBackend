<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\OrderExtensionOffer;
use App\Models\User;
use App\Services\Notifier;
use App\Services\Sales\SalesSettings;
use App\Support\JalaliDate;
use Illuminate\Support\Facades\DB;

/**
 * پیشنهاد/پذیرش/ردِ تمدیدِ رزرو — تصمیم مدیریت ۲۰۲۶-۰۹-۰۹، پرسش ۳.
 *
 * «رزروِ اول را هیچ‌کس نمی‌تواند کنسل کند. پس از پایانش، تمدید را تأمین‌کننده
 * پیشنهاد و خریدار می‌پذیرد یا رد می‌کند؛ ردِ هرکدام = کنسل. با توافق طرفین،
 * دفعات و مدت نامحدود.» و رایگان — هزینه‌ی ۵ میلیونیِ سابق عمداً حذف شده.
 *
 * سه قاعده‌ای که این کلاس نگه می‌دارد:
 *
 * ۱. **پیشنهاد فقط وقتی رزرو تمام شده یا در حالِ تمام‌شدن است** —
 *    `Order::canProposeReserveExtension()`. بیرون از آن بازه، مهلتی که خریدار
 *    رویش حساب کرده هنوز زنده است و «تمدید» معنایی ندارد.
 * ۲. **یک پیشنهادِ باز به‌ازای هر سفارش.** دو پیشنهادِ هم‌زمان یعنی خریدار یکی
 *    را می‌پذیرد و دیگری معلق می‌ماند.
 * ۳. **مهلت را فقط پذیرشِ خریدار جابه‌جا می‌کند**، و از همان
 *    `ExtendReserveDeadlineAction` قدیمی (افزایشی، زیر قفل). رد از
 *    `CancelOrderAction` می‌گذرد تا بیعانه با همان مسیرِ همیشگی برگردد —
 *    هیچ‌کدام این‌جا دوباره نوشته نشده.
 *
 * مهلتِ پاسخِ خریدار هرکدام زودتر است: پایانِ ارفاقِ رزرو (`reserveEndsAt()`، کرونِ
 * `orders:expire-reserves` در آن لغو می‌کند) یا `proposed_until` —
 * `OrderExtensionOffer::respondUntil()`. پیشنهاد **کرون را متوقف نمی‌کند** — وگرنه
 * یک پیشنهادِ بی‌پاسخ موجودی را تا ابد قفل نگه می‌داشت.
 *
 * ⚠️ هیچ کرونی پیشنهاد را `expired` نمی‌کند. دو گارد جایش را می‌گیرند: پاسخ به
 * پیشنهادی که سفارشش دیگر `reserved` نیست ۴۰۹ است (`lockPending`)، و پیشنهادِ
 * lapsed هنگامِ پیشنهادِ تازه `expired` می‌شود. بدونِ گاردِ اول، «رد»ِ پیشنهادِ
 * مانده روی سفارشی که پرداختش کامل شده بود، آن را یک‌طرفه لغو و مسترد می‌کرد.
 *
 * پوششِ تست: tests/Feature/Order/ReserveExtensionOfferTest.php
 */
final class ReserveExtensionAction
{
    public function __construct(
        private readonly SalesSettings $settings,
        private readonly ExtendReserveDeadlineAction $extend,
        private readonly CancelOrderAction $cancel,
    ) {}

    /** تأمین‌کننده پیشنهاد می‌دهد: «تا :hours ساعت پس از پایانِ مهلتِ فعلی». */
    public function propose(Order $order, int $supplierUserId, int $hours): OrderExtensionOffer
    {
        $offer = DB::transaction(function () use ($order, $supplierUserId, $hours) {
            /** @var Order $locked */
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked->canProposeReserveExtension($this->settings->reserveWarningThreshold())) {
                throw new BusinessException(__('orders.extension.not_ending'), 422);
            }

            // پیشنهادی که `proposed_until`اش گذشته دیگر پذیرفتنی نیست (accept ۴۲۲ می‌دهد).
            // اگر جلوی پیشنهادِ تازه را هم می‌گرفت، تا لغوِ کرون تنها راهِ خریدار «رد = کنسل» بود.
            $pending = $locked->pendingExtensionOffer()->lockForUpdate()->first();

            if ($pending !== null && ! $pending->isLapsed()) {
                throw new BusinessException(__('orders.extension.already_pending'), 409);
            }

            $pending?->forceFill([OrderExtensionOffer::STATUS => OrderExtensionOffer::STATUS_EXPIRED])->save();

            // مبنا مهلتِ فعلی است، مگر اینکه گذشته باشد — آن‌وقت «از همین حالا».
            // وگرنه پیشنهادِ «۲ ساعت» روی مهلتی که ۳ ساعت پیش تمام شده، مهلتی در
            // گذشته می‌ساخت که `ExtendReserveDeadlineAction` ردش می‌کرد.
            $base = $locked->{Order::RESERVE_DEADLINE}->isFuture()
                ? $locked->{Order::RESERVE_DEADLINE}->copy()
                : now();

            return OrderExtensionOffer::create([
                OrderExtensionOffer::ORDER_ID => $locked->getKey(),
                OrderExtensionOffer::SUPPLIER_USER_ID => $supplierUserId,
                OrderExtensionOffer::HOURS => $hours,
                OrderExtensionOffer::PROPOSED_UNTIL => $base->addHours($hours),
            ]);
        });

        // پیامک به پرداخت‌کننده (قاعده‌ی تیم بک‌اند: رویدادِ مالی به پرداخت‌کننده).
        $this->notify($order->payerUserId(), 'reserve_extension_proposed', $order, [
            'until' => JalaliDate::format($offer->{OrderExtensionOffer::PROPOSED_UNTIL}),
            'respond_until' => JalaliDate::format($offer->respondUntil($order)),
        ]);

        return $offer;
    }

    /** خریدار می‌پذیرد → مهلتِ رزرو تا `proposed_until` جلو می‌رود. */
    public function accept(OrderExtensionOffer $offer): Order
    {
        $order = DB::transaction(function () use ($offer) {
            $locked = $this->lockPending($offer);

            // پیشنهادی که خودش گذشته، پذیرفتنش مهلتی در گذشته می‌ساخت — و کرون
            // همان دقیقه سفارش را لغو می‌کرد. ۴۲۲ صادقانه‌تر از یک «پذیرفته شد»ِ
            // بی‌اثر است.
            if ($locked->{OrderExtensionOffer::PROPOSED_UNTIL}->isPast()) {
                throw new BusinessException(__('orders.extension.offer_lapsed'), 422);
            }

            $order = $this->extend->execute($locked->order, $locked->{OrderExtensionOffer::PROPOSED_UNTIL});

            $locked->forceFill([
                OrderExtensionOffer::STATUS => OrderExtensionOffer::STATUS_ACCEPTED,
                OrderExtensionOffer::RESPONDED_AT => now(),
            ])->save();

            return $order;
        });

        $this->notify($offer->{OrderExtensionOffer::SUPPLIER_USER_ID}, 'reserve_extension_accepted', $order, [
            'until' => JalaliDate::format($order->{Order::RESERVE_DEADLINE}),
        ]);

        return $order;
    }

    /**
     * خریدار رد می‌کند → «ردِ هرکدام = کنسل»: سفارش با مسیرِ همیشگیِ لغوِ رزرو
     * بسته می‌شود (بیعانه از هولدِ سازه به کیف پول خریدار برمی‌گردد).
     */
    public function reject(OrderExtensionOffer $offer): Order
    {
        $order = DB::transaction(function () use ($offer) {
            $locked = $this->lockPending($offer);

            $locked->forceFill([
                OrderExtensionOffer::STATUS => OrderExtensionOffer::STATUS_REJECTED,
                OrderExtensionOffer::RESPONDED_AT => now(),
            ])->save();

            // `isSystemCron: true` یعنی «مشمولِ پنجره‌ی لغوِ خریدار نیست» (نامش
            // تاریخی است — CancelOrderAction را ببینید). بدونِ آن، رزروِ
            // تمدیدنشده روی گاردِ `isInBuyerCancelWindow()` می‌شکست، در حالی که
            // این لغو دقیقاً تصمیمِ مدیریت است.
            return $this->cancel->execute(
                $locked->order,
                __('orders.extension.rejected_cancel_reason'),
                refundWallet: true,
                isSystemCron: true,
            );
        });

        $this->notify($offer->{OrderExtensionOffer::SUPPLIER_USER_ID}, 'reserve_extension_rejected', $order);

        return $order;
    }

    private function lockPending(OrderExtensionOffer $offer): OrderExtensionOffer
    {
        // سفارش پیش از پیشنهاد قفل می‌شود — همان ترتیبِ `propose()`، تا دو مسیر روی
        // یک سفارش هم‌دیگر را در بن‌بست نگذارند.
        /** @var Order|null $order */
        $order = Order::whereKey($offer->{OrderExtensionOffer::ORDER_ID})->lockForUpdate()->first();

        /** @var OrderExtensionOffer $locked */
        $locked = OrderExtensionOffer::whereKey($offer->getKey())->lockForUpdate()->first();

        if (! $locked->isPending()) {
            throw new BusinessException(__('orders.extension.already_answered'), 409);
        }

        // پیشنهاد فقط تا وقتی معنا دارد که سفارش هنوز رزرو است. پیشنهادی که پس از
        // تکمیلِ پرداخت باز مانده بود، «رد» را به لغو و استردادِ یک‌طرفه‌ی سفارشِ
        // پرداخت‌شده تبدیل می‌کرد — همان لغوی که پرسش ۴ دوطرفه کرده (بازبینی ۲۰۲۶-۰۹-۱۴).
        if ($order === null || $order->{Order::STATUS} !== Order::STATUS_RESERVED) {
            throw new BusinessException(__('orders.extension.order_moved_on'), 409);
        }

        return $locked->setRelation('order', $order);
    }

    /** @param  array<string, string>  $extra */
    private function notify(?int $userId, string $key, Order $order, array $extra = []): void
    {
        $user = $userId ? User::find($userId) : null;

        if (! $user instanceof User) {
            return;
        }

        Notifier::template(
            $user,
            __('notifications.'.$key.'_subject'),
            $key,
            ['order' => $order->order_number] + $extra,
        );
    }
}
