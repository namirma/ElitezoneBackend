<?php

namespace App\Sms;

use App\Events\Financial\TransactionProcessed;
use App\Events\Financial\WalletFunded;
use App\Events\Order\OrderCancelled;
use App\Events\Order\OrderPaid;
use App\Events\Order\OrderPlaced;
use App\Events\User\CustomerSegmentChanged;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Models\WalletTransaction;

/**
 * فهرستِ رویدادهایی که ادمین می‌تواند رویشان پیامکِ سفارشی بسازد — بدونِ کد.
 *
 * ## چه چیزی هست و چه چیزی نیست
 *
 * این فهرست فقط رویدادهای دامنه‌ای است که سیستم **همین حالا** می‌زند
 * (`app/Events`). هر ورودی می‌گوید آن رویداد چه مخاطبانی دارد (و هر مخاطب از
 * کجای رویداد درمی‌آید) و چه جانگهدارهایی می‌دهد. `SendCustomSmsForEvent` —
 * یک لیسنرِ عمومی که روی همه‌ی این رویدادها ثبت شده — قالب‌های سفارشیِ فعالِ
 * `event + audience` را برای این مخاطبان می‌فرستد.
 *
 * `CartItemChanged` عمداً نیست: روی هر تغییرِ سبد شلیک می‌شود و یک قالبِ
 * سفارشی رویش یعنی چند پیامک به‌ازای هر خرید. رویدادی که این‌جا نیست، هنوز
 * توسعه‌دهنده می‌خواهد — و متنِ پنل همین را صادقانه می‌گوید.
 *
 * ## مخاطبان
 *
 * مقدارها همان `SmsTemplate::AUDIENCES` است. برای رویدادهای سفارش:
 * `buyer` = ثبت‌کننده، `employer` = ذی‌نفعِ «خرید برای دیگری» (کارفرما)،
 * `elite_yar` = الیت‌یارِ سفارش، `supplier` = تأمین‌کننده. برای رویدادهای
 * کیف پول و بخش‌بندی فقط `any` = کاربرِ خودِ رویداد.
 */
final class EventCatalog
{
    /**
     * `resolutions` می‌گوید هر مخاطب **از کجای رویداد** پیدا می‌شود — همان چیزی که
     * `recipients()` پایین‌تر اجرا می‌کند، به زبانِ آدم، تا پنل بتواند نشانش دهد.
     *
     * @return array<class-string, array{label: string, description: string, audiences: array<string, string>, resolutions: array<string, string>, placeholders: list<string>}>
     */
    public static function all(): array
    {
        $orderAudiences = [
            SmsTemplate::AUDIENCE_BUYER => 'ثبت‌کننده‌ی سفارش',
            SmsTemplate::AUDIENCE_EMPLOYER => 'کارفرما (ذی‌نفعِ خرید برای دیگری)',
            SmsTemplate::AUDIENCE_ELITE_YAR => 'الیت‌یارِ سفارش',
            SmsTemplate::AUDIENCE_SUPPLIER => 'تأمین‌کننده‌ی سفارش',
        ];
        $orderResolutions = [
            SmsTemplate::AUDIENCE_BUYER => 'کاربرِ placer_user_id سفارش — کسی که سفارش را ثبت کرده',
            SmsTemplate::AUDIENCE_EMPLOYER => 'کاربرِ beneficiary_user_id — فقط وقتی سفارش «به نامِ دیگری» است؛ وگرنه کسی نمی‌گیرد',
            SmsTemplate::AUDIENCE_ELITE_YAR => 'کاربرِ elite_yar_user_id — فقط اگر الیت‌یاری روی سفارش باشد',
            SmsTemplate::AUDIENCE_SUPPLIER => 'تأمین‌کننده‌ی اقلامِ سفارش (supplier_user_id روی آیتم‌ها؛ یک سفارش یک تأمین‌کننده)',
        ];
        $orderPlaceholders = ['order_number', 'amount', 'name'];
        $walletOwner = [SmsTemplate::AUDIENCE_ANY => 'کاربرِ صاحبِ کیف پولی که تراکنش رویش ثبت شده'];

        return [
            OrderPlaced::class => [
                'label' => 'ثبت سفارش',
                'description' => 'هر بار که سفارشی ثبت می‌شود — چه رزرو، چه پرداخت‌شده.',
                'audiences' => $orderAudiences,
                'resolutions' => $orderResolutions,
                'placeholders' => $orderPlaceholders,
            ],
            OrderPaid::class => [
                'label' => 'پرداخت سفارش',
                'description' => 'وقتی سفارش کامل پرداخت می‌شود و به صفِ کاریِ تأمین‌کننده می‌رود.',
                'audiences' => $orderAudiences,
                'resolutions' => $orderResolutions,
                'placeholders' => $orderPlaceholders,
            ],
            OrderCancelled::class => [
                'label' => 'لغو سفارش',
                'description' => 'وقتی سفارش لغو می‌شود — توسط خریدار، ادمین یا انقضای رزرو.',
                'audiences' => $orderAudiences,
                'resolutions' => $orderResolutions,
                'placeholders' => $orderPlaceholders,
            ],
            WalletFunded::class => [
                'label' => 'شارژ کیف پول',
                'description' => 'وقتی کیف پول کاربر شارژ می‌شود (درگاه یا تأیید واریز پادیوم).',
                'audiences' => [SmsTemplate::AUDIENCE_ANY => 'صاحب کیف پول'],
                'resolutions' => $walletOwner,
                'placeholders' => ['amount', 'name'],
            ],
            TransactionProcessed::class => [
                'label' => 'تصمیم ادمین روی تراکنش',
                'description' => 'وقتی ادمین درخواست واریز/برداشت را تأیید یا رد می‌کند.',
                'audiences' => [SmsTemplate::AUDIENCE_ANY => 'صاحب کیف پول'],
                'resolutions' => $walletOwner,
                'placeholders' => ['amount', 'type', 'status', 'name'],
            ],
            CustomerSegmentChanged::class => [
                'label' => 'تغییر بخشِ مشتری',
                'description' => 'وقتی تحلیلِ رفتار، بخشِ مشتری را عوض می‌کند (مثلاً «در خطر»).',
                'audiences' => [SmsTemplate::AUDIENCE_ANY => 'کاربر'],
                'resolutions' => [SmsTemplate::AUDIENCE_ANY => 'همان کاربری که بخشش عوض شده (خروجیِ کرونِ RFM)'],
                'placeholders' => ['name', 'old_segment', 'new_segment'],
            ],
        ];
    }

    /** @return list<class-string> */
    public static function events(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $event): bool
    {
        return array_key_exists($event, self::all());
    }

    /** @return list<string> */
    public static function audiencesFor(string $event): array
    {
        return array_keys(self::all()[$event]['audiences'] ?? []);
    }

    /**
     * گیرندگانِ یک مخاطب از روی خودِ رویداد. کاربرِ بدونِ شماره حذف می‌شود.
     *
     * @return list<User>
     */
    public static function recipients(object $event, string $audience): array
    {
        $users = match (true) {
            $event instanceof OrderPlaced,
            $event instanceof OrderPaid,
            $event instanceof OrderCancelled => self::orderRecipients($event->order, $audience),
            $event instanceof WalletFunded => [$event->user],
            $event instanceof TransactionProcessed => [$event->transaction->wallet->user],
            $event instanceof CustomerSegmentChanged => [$event->user],
            default => [],
        };

        return array_values(array_filter(
            $users,
            fn ($u) => $u instanceof User && ! empty($u->mobile),
        ));
    }

    /**
     * جانگهدارهای یک رویداد برای یک گیرنده‌ی مشخص.
     *
     * @return array<string, string>
     */
    public static function params(object $event, User $recipient): array
    {
        $name = trim((string) $recipient->full_name) ?: (string) $recipient->mobile;

        return match (true) {
            $event instanceof OrderPlaced,
            $event instanceof OrderPaid,
            $event instanceof OrderCancelled => [
                'order_number' => (string) $event->order->{Order::ORDER_NUMBER},
                'amount' => number_format((float) $event->order->{Order::FINAL_AMOUNT}),
                'name' => $name,
            ],
            $event instanceof WalletFunded => [
                'amount' => number_format((float) $event->transaction->{WalletTransaction::AMOUNT}),
                'name' => $name,
            ],
            $event instanceof TransactionProcessed => [
                'amount' => number_format((float) $event->transaction->{WalletTransaction::AMOUNT}),
                'type' => (string) $event->transaction->{WalletTransaction::TYPE},
                'status' => (string) $event->transaction->{WalletTransaction::STATUS},
                'name' => $name,
            ],
            $event instanceof CustomerSegmentChanged => [
                'name' => $name,
                'old_segment' => $event->oldSegment,
                'new_segment' => $event->newSegment,
            ],
            default => [],
        };
    }

    /** @return list<User|null> */
    private static function orderRecipients(Order $order, string $audience): array
    {
        return match ($audience) {
            SmsTemplate::AUDIENCE_BUYER => [User::query()->find($order->{Order::PLACER_USER_ID})],
            SmsTemplate::AUDIENCE_EMPLOYER => [User::query()->find($order->{Order::BENEFICIARY_USER_ID})],
            SmsTemplate::AUDIENCE_ELITE_YAR => [User::query()->find($order->getAttribute(Order::ELITE_YAR_USER_ID))],
            SmsTemplate::AUDIENCE_SUPPLIER => User::query()
                ->whereIn('id', $order->items()->pluck(OrderItem::SUPPLIER_USER_ID)->filter()->unique())
                ->get()
                ->all(),
            default => [],
        };
    }
}
