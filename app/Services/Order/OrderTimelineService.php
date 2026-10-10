<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Constants\FinancialCodes;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\JalaliDate;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * روایتِ زمانیِ یک سفارش — همه‌ی رویدادها از همه‌ی جدول‌ها، در یک خط.
 *
 * چرا لازم است
 * ────────────
 * جوابِ «سرِ سفارش ۴۸۲۱ چه آمد؟» امروز یعنی باز کردنِ پنج منبعِ جدا:
 *
 *   • ستون‌های زمانیِ خودِ `orders` (پرداخت، آماده‌سازی، باسکول، حمل، آزادسازیِ هولدها)
 *   • `order_status_history` (گذارهای وضعیت + اینکه چه کسی آن را زد)
 *   • `wallet_transactions` (هر حرکتِ پول، با کدِ کیف در meta)
 *   • `invoices` (اسنادِ A تا T و شماره‌های مودیان)
 *   • فیلدهای مرجعِ روی سفارش (بارنامه، مودیانِ سوپلایر/سیستم/الیت‌یار)
 *
 * هیچ‌کدام به‌تنهایی داستان را نمی‌گوید و ترتیبِ بینشان هم جایی ثبت نشده. این سرویس
 * همه را در یک آرایه‌ی مرتب‌شده ادغام می‌کند و فاصله‌ی زمانیِ هر رویداد تا رویدادِ قبلی
 * را هم حساب می‌کند — چون در عملِ پشتیبانی، «کجا گیر کرد» تقریباً همیشه از همان
 * فاصله‌ها پیدا می‌شود، نه از خودِ رویدادها.
 *
 * تفاوتش با `AdminOrderController::history`: آن فقط لاگِ spatie را برمی‌گرداند
 * (چه چیزی تغییر کرد)، این پنج منبع را روایت می‌کند (چه اتفاقی افتاد).
 *
 * فقط می‌خواند — هیچ رویدادی نمی‌سازد و چیزی را تغییر نمی‌دهد.
 */
final class OrderTimelineService
{
    /** ستون‌های زمانیِ سفارش و عنوانِ رویدادی که هرکدام نشان می‌دهند. */
    private const LIFECYCLE_COLUMNS = [
        Order::CREATED_AT => 'سفارش ثبت شد',
        Order::PAID_AT => 'پرداخت انجام شد',
        Order::PREPARED_AT => 'آماده‌سازی تمام شد',
        Order::WEIGHBRIDGE_REGISTERED_AT => 'در باسکول ثبت شد',
        Order::WEIGHING_COMPLETED_AT => 'توزین کامل شد',
        Order::SHIPPED_AT => 'بار ارسال شد',
        Order::ELITE_YAR_HOLD_RELEASED_AT => 'هولد کمیسیون الیت یار آزاد شد',
        Order::SUPPLIER_HOLD_RELEASED_AT => 'هولد مالیات تأمین‌کننده آزاد شد',
        Order::SYSTEM_HOLD_RELEASED_AT => 'هولد مالیات پلتفرم آزاد شد',
    ];

    /** کدِ کیف پول (در meta تراکنش) → طرفِ حساب. */
    private const WALLET_PARTIES = [
        FinancialCodes::WALLET_OWNER => 'خریدار',
        FinancialCodes::WALLET_SUPPLIER => 'تأمین‌کننده',
        FinancialCodes::WALLET_SAZE => 'پلتفرم (سازه)',
        FinancialCodes::WALLET_ELITE_YAR => 'الیت یار',
        FinancialCodes::WALLET_VAT => 'مالیات (امانی)',
    ];

    /**
     * @return array{
     *     order: array<string, mixed>,
     *     events: array<int, array<string, mixed>>,
     *     span: array{from: string|null, to: string|null, days: int|null}
     * }
     */
    public function build(Order $order): array
    {
        $events = collect()
            ->concat($this->lifecycleEvents($order))
            ->concat($this->statusEvents($order))
            ->concat($this->moneyEvents($order))
            ->concat($this->documentEvents($order))
            // ترتیبِ ثانویه با `track` لازم است: چند رویداد اغلب در یک ثانیه ثبت
            // می‌شوند (کلِ تسویه داخل یک DB::transaction است) و بدون آن ترتیبِ
            // نمایش بین دو اجرا عوض می‌شد.
            ->sortBy([['at', 'asc'], ['track', 'asc']])
            ->values();

        return [
            'order' => $this->summary($order),
            'events' => $this->withGaps($events),
            'span' => $this->span($events),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // منابع
    // ─────────────────────────────────────────────────────────────────────────

    /** @return Collection<int, array<string, mixed>> */
    private function lifecycleEvents(Order $order): Collection
    {
        /** @var array<int, array<string, mixed>> $events */
        $events = [];

        foreach (self::LIFECYCLE_COLUMNS as $column => $title) {
            // getAttribute به‌جای دسترسی داینامیک: larastan بعضی از این ستون‌ها را
            // نمی‌شناسد (elite_yar_hold_released_at همین حالا هم دو ورودی در
            // baseline دارد) و دسترسی مستقیم بدهیِ تازه به baseline اضافه می‌کرد.
            $at = $order->getAttribute($column);

            if ($at === null) {
                continue;
            }

            $events[] = $this->event(
                at: Carbon::parse($at),
                track: 'lifecycle',
                title: $title,
                detail: $this->lifecycleDetail($order, $column),
            );
        }

        return collect($events);
    }

    /** جزئیاتی که فقط برای بعضی مراحل معنا دارند (مثلاً وزن، بعد از باسکول). */
    private function lifecycleDetail(Order $order, string $column): ?string
    {
        return match ($column) {
            Order::WEIGHING_COMPLETED_AT => $this->weightDetail($order),
            Order::PAID_AT => $order->{Order::PAYMENT_METHOD}
                ? 'روش پرداخت: '.$order->{Order::PAYMENT_METHOD}
                : null,
            Order::SHIPPED_AT => $order->{Order::WAYBILL_REF}
                ? 'بارنامه '.$order->{Order::WAYBILL_REF}
                : null,
            default => null,
        };
    }

    private function weightDetail(Order $order): ?string
    {
        $before = $order->{Order::WEIGHT_BEFORE_SCALE};
        $after = $order->{Order::WEIGHT_AFTER_SCALE};

        if ($before === null || $after === null) {
            return null;
        }

        $diff = (float) $after - (float) $before;

        // اختلافِ وزن همان چیزی است که کلِ تسویه‌ی پس از باسکول رویش می‌چرخد
        // (بدهیِ سوپلایر یا پرداختِ مازادِ خریدار)، پس صریح نوشته می‌شود.
        return sprintf(
            'وزن اولیه %s، وزن باسکول %s، اختلاف %s کیلوگرم',
            $this->number($before),
            $this->number($after),
            ($diff > 0 ? '+' : '').$this->number($diff),
        );
    }

    /** @return Collection<int, array<string, mixed>> */
    private function statusEvents(Order $order): Collection
    {
        $rows = OrderStatusHistory::query()
            ->where(OrderStatusHistory::ORDER_ID, $order->{Order::ID})
            ->orderBy(OrderStatusHistory::CREATED_AT)
            ->get();

        $actors = $this->actorNames($rows->pluck(OrderStatusHistory::USER_ID));

        return $rows->map(fn (OrderStatusHistory $row) => $this->event(
            // `OrderStatusHistory::$timestamps = false` است، پس created_at را باید
            // نویسنده صریحاً بگذارد. اگر جایی فراموش شده باشد، `Carbon::parse(null)`
            // «همین حالا» می‌داد و رویدادِ کهنه در انتهای روایت ظاهر می‌شد — انگار
            // همین لحظه رخ داده. زمانِ ثبتِ سفارش دستِ‌کم آن را در دوره‌ی درست نگه می‌دارد.
            at: Carbon::parse($row->{OrderStatusHistory::CREATED_AT} ?? $order->{Order::CREATED_AT}),
            track: 'status',
            title: sprintf(
                'وضعیت: %s ← %s',
                $this->statusLabel($row->{OrderStatusHistory::FROM_STATUS}),
                $this->statusLabel($row->{OrderStatusHistory::TO_STATUS}),
            ),
            detail: $row->{OrderStatusHistory::DURATION_MINUTES} !== null
                ? sprintf('%s دقیقه در وضعیت قبلی', $this->number($row->{OrderStatusHistory::DURATION_MINUTES}))
                : null,
            actor: $actors[$row->{OrderStatusHistory::USER_ID}] ?? null,
        ));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function moneyEvents(Order $order): Collection
    {
        return WalletTransaction::query()
            ->with('wallet')
            ->where(WalletTransaction::ORDER_ID, $order->{Order::ID})
            ->orderBy(WalletTransaction::ID)
            ->get()
            ->map(fn (WalletTransaction $txn) => $this->event(
                at: Carbon::parse($txn->{WalletTransaction::CREATED_AT}),
                track: 'money',
                title: $this->moneyTitle($txn),
                detail: $txn->{WalletTransaction::DESCRIPTION},
                amount: (string) (int) $txn->{WalletTransaction::AMOUNT},
                meta: [
                    'wallet_code' => $txn->{WalletTransaction::META}['code'] ?? null,
                    'wallet_id' => $txn->{WalletTransaction::WALLET_ID},
                    'user_id' => $txn->wallet->{Wallet::USER_ID},
                    'status' => $txn->{WalletTransaction::STATUS},
                ],
            ));
    }

    private function moneyTitle(WalletTransaction $txn): string
    {
        $code = $txn->{WalletTransaction::META}['code'] ?? null;
        $party = self::WALLET_PARTIES[$code] ?? 'کیف پول';
        $amount = Money::format($txn->{WalletTransaction::AMOUNT});

        if ($txn->{WalletTransaction::TYPE} === WalletTransaction::TYPE_HOLD_RELEASE) {
            return "آزادسازی هولد {$party}: {$amount}";
        }

        $isHold = $txn->{WalletTransaction::STATUS} === WalletTransaction::STATUS_HOLD;
        $direction = $txn->{WalletTransaction::TYPE} === WalletTransaction::TYPE_DEPOSIT ? 'بستانکار' : 'بدهکار';

        return $isHold
            ? "{$party} {$direction} (هولد): {$amount}"
            : "{$party} {$direction}: {$amount}";
    }

    /** @return Collection<int, array<string, mixed>> */
    private function documentEvents(Order $order): Collection
    {
        return Invoice::query()
            ->where('order_id', $order->{Order::ID})
            ->orderBy('id')
            ->get()
            ->map(fn (Invoice $invoice) => $this->event(
                at: Carbon::parse($invoice->created_at),
                track: 'document',
                title: sprintf(
                    'سند %s — %s',
                    $invoice->document_code,
                    $this->documentLabel((string) $invoice->document_code),
                ),
                detail: sprintf('شماره %s', $invoice->invoice_number),
                amount: (float) $invoice->amount > 0 ? (string) (int) $invoice->amount : null,
                meta: ['document_code' => $invoice->document_code, 'status' => $invoice->status],
            ));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ساخت رویداد و پس‌پردازش
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function event(
        Carbon $at,
        string $track,
        string $title,
        ?string $detail = null,
        ?string $actor = null,
        ?string $amount = null,
        array $meta = [],
    ): array {
        return [
            'at' => $at->toDateTimeString(),
            'at_jalali' => $this->jalali($at),
            'track' => $track,
            'title' => $title,
            'detail' => $detail,
            'actor' => $actor,
            'amount' => $amount,
            'meta' => $meta,
        ];
    }

    /**
     * فاصله‌ی هر رویداد تا رویدادِ قبلی.
     *
     * این ستون دلیلِ وجودِ کلِ تایم‌لاین است: خودِ رویدادها را می‌شود در جدول‌ها هم
     * دید، ولی «۹ روز بینِ باسکول و بارنامه» فقط وقتی به چشم می‌آید که کنارِ هم
     * چیده شوند.
     *
     * @param  Collection<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    private function withGaps(Collection $events): array
    {
        $previous = null;

        return $events->map(function (array $event) use (&$previous) {
            $at = Carbon::parse($event['at']);
            $event['gap'] = $previous === null ? null : $this->humanGap($previous, $at);
            $previous = $at;

            return $event;
        })->all();
    }

    private function humanGap(Carbon $from, Carbon $to): string
    {
        $seconds = (int) $from->diffInSeconds($to);

        if ($seconds < 60) {
            return 'هم‌زمان';
        }

        if ($seconds < 3600) {
            return $this->number(intdiv($seconds, 60)).' دقیقه بعد';
        }

        if ($seconds < 86_400) {
            return $this->number(intdiv($seconds, 3600)).' ساعت بعد';
        }

        return $this->number(intdiv($seconds, 86_400)).' روز بعد';
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $events
     * @return array{from: string|null, to: string|null, days: int|null}
     */
    private function span(Collection $events): array
    {
        if ($events->isEmpty()) {
            return ['from' => null, 'to' => null, 'days' => null];
        }

        $first = Carbon::parse($events->first()['at']);
        $last = Carbon::parse($events->last()['at']);

        return [
            'from' => $this->jalali($first),
            'to' => $this->jalali($last),
            'days' => (int) $first->diffInDays($last),
        ];
    }

    /** @return array<string, mixed> */
    private function summary(Order $order): array
    {
        return [
            'id' => $order->{Order::ID},
            'order_number' => $order->{Order::ORDER_NUMBER},
            'status' => $order->{Order::STATUS},
            'status_label' => $this->statusLabel($order->{Order::STATUS}),
            'payment_status' => $order->{Order::PAYMENT_STATUS},
            'final_amount' => $order->{Order::FINAL_AMOUNT},
            'payment_of_owner' => $order->{Order::PAYMENT_OF_OWNER},
            'payment_to_supplier' => $order->{Order::PAYMENT_TO_SUPPLIER},
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // کمکی
    // ─────────────────────────────────────────────────────────────────────────

    private function statusLabel(?string $status): string
    {
        if ($status === null || $status === '') {
            return '—';
        }

        // عمداً `orders.order_status` نه `orders.status`: دومی وضعیتِ سفارش و
        // پرداخت را در یک فضای نام دارد و کلیدهای مشترکشان (pending/paid/failed)
        // روی هم می‌افتند — یعنی یک سفارشِ «pending» آنجا «در انتظار پرداخت سهم»
        // برچسب می‌خورد. توضیح کامل در lang/fa/orders.php.
        $key = 'orders.order_status.'.$status;
        $label = __($key);

        // `__()` وقتی کلید نباشد خودِ کلید را برمی‌گرداند؛ نمایشِ
        // «orders.order_status.foo» به کاربر بدتر از نمایشِ خودِ اسلاگ است.
        return $label === $key ? $status : $label;
    }

    private function documentLabel(string $code): string
    {
        $key = 'orders.document_codes.'.$code;
        $label = __($key);

        return $label === $key ? 'سند نامشخص' : $label;
    }

    /**
     * @param  Collection<int, int|null>  $userIds
     * @return array<int, string>
     */
    private function actorNames(Collection $userIds): array
    {
        $ids = $userIds->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return User::query()
            ->with('profile')
            ->whereIn(User::ID, $ids)
            ->get()
            ->mapWithKeys(fn (User $user) => [$user->{User::ID} => $user->full_name])
            ->all();
    }

    private function number(int|float|string|null $value): string
    {
        return number_format((float) $value, 0, '.', ',');
    }

    /** تاریخ شمسی به ساعتِ تهران — شرح در `JalaliDate`. */
    private function jalali(Carbon $at): string
    {
        return JalaliDate::format($at);
    }
}
