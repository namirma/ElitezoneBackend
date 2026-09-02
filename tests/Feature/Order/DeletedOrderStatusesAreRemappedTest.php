<?php

namespace Tests\Feature\Order;

use App\Enums\Order\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * هیچ ردیفی نباید با وضعیتی خارج از `OrderStatus` بماند.
 *
 * بند ۵.۲ سند هشت وضعیت را حذف کرد، ولی مهاجرتِ نگاشت **هفت‌تا** را پوشش داد؛
 * `pending` جا افتاد. چون `orders.status` یک `varchar` است، نه دیتابیس جلویش را
 * گرفت و نه هیچ تستی قرمز شد: سوئیت روی دیتابیسِ تازه با `DatabaseTransactions`
 * اجرا می‌شود و **هرگز ردیفِ قدیمی نمی‌بیند**.
 *
 * اثرش هم خطا نبود، غیبت بود: هر سه پارتیشنِ
 * `OrderBuilder::current/delivered/cancelled` ردش می‌کنند، پس سفارش از هر سه
 * تبِ «سفارش‌های من» ناپدید می‌شود و در بک‌آفیس «نامشخص» رندر می‌شود.
 *
 * این تست عمداً **سوئیپ** است نه نمونه‌ای — نسخه‌ی نمونه‌ای دقیقاً همان چیزی
 * بود که `pending` را از قلم انداخت. همان الگوی
 * `Admin/AdminPermissionCoverageTest`.
 */
class DeletedOrderStatusesAreRemappedTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * هشت وضعیتِ حذف‌شده‌ی بند ۵.۲.
     *
     * @var list<string>
     */
    private const DELETED = [
        'pending',
        'reserved_extended',
        'preparing',
        'weighbridge_done',
        'waybill_issued',
        'pending_surplus_payment',
        'completed',
        'failed',
    ];

    /**
     * @var list<string>
     */
    private const MIGRATIONS = [
        '2026_08_11_110000_remap_deleted_order_statuses.php',
        '2026_09_02_100000_remap_legacy_pending_order_status.php',
    ];

    private function runRemapMigrations(): void
    {
        foreach (self::MIGRATIONS as $file) {
            (require database_path('migrations/'.$file))->up();
        }
    }

    /** @return list<string> */
    private function enumValues(): array
    {
        return array_map(fn (OrderStatus $c) => $c->value, OrderStatus::cases());
    }

    #[Test]
    public function every_deleted_status_lands_inside_the_enum(): void
    {
        $ids = [];

        foreach (self::DELETED as $status) {
            $order = Order::factory()->create([Order::PAYMENT_STATUS => Order::PAYMENT_PENDING]);
            // `status` در `$fillable` هست ولی factory مقدارِ خودش را می‌گذارد؛
            // نوشتنِ مستقیم تنها راهِ ساختنِ یک ردیفِ «قدیمی» است.
            DB::table('orders')->where('id', $order->id)->update(['status' => $status]);
            $ids[$status] = $order->id;
        }

        $this->runRemapMigrations();

        $enum = $this->enumValues();

        foreach ($ids as $old => $id) {
            $new = DB::table('orders')->where('id', $id)->value('status');

            $this->assertContains(
                $new,
                $enum,
                "وضعیتِ حذف‌شده‌ی `{$old}` بعد از مهاجرت روی `{$new}` مانده که در enum نیست — ".
                'سفارش از هر سه تبِ «سفارش‌های من» ناپدید می‌شود.'
            );
        }
    }

    #[Test]
    public function an_unpaid_legacy_pending_order_is_cancelled(): void
    {
        $order = Order::factory()->create([Order::PAYMENT_STATUS => Order::PAYMENT_PENDING]);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'pending']);

        $this->runRemapMigrations();

        // در مدل جدید سفارشِ پرداخت‌نشده اصلاً ساخته نمی‌شود، پس یک `pending`
        // قدیمی هرگز قابل پرداخت نیست — همان جایی می‌رود که `failed` رفت.
        $this->assertSame(
            Order::STATUS_CANCELLED,
            DB::table('orders')->where('id', $order->id)->value('status')
        );
    }

    #[Test]
    public function a_paid_legacy_pending_order_is_never_silently_cancelled(): void
    {
        // ردیفی که پرداخت شده ولی وضعیتش `pending` مانده یعنی نوشتنِ وضعیت
        // نیمه‌کاره مانده. لغوش پولِ گرفته‌شده را پشتِ برچسبِ «لغو شده» پنهان
        // می‌کند بی‌آنکه هیچ لِگِ بازگشتی اجرا شود.
        $order = Order::factory()->create([Order::PAYMENT_STATUS => Order::PAYMENT_PAID]);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'pending']);

        $this->runRemapMigrations();

        $new = DB::table('orders')->where('id', $order->id)->value('status');

        $this->assertNotSame(Order::STATUS_CANCELLED, $new, 'سفارشِ پرداخت‌شده نباید بی‌صدا لغو شود.');
        $this->assertSame(Order::STATUS_PROCESSING, $new);
    }

    #[Test]
    public function the_three_partitions_together_cover_the_whole_enum(): void
    {
        // علتِ ریشه‌ایِ «ناپدید شدن»: پارتیشن‌ها اجتماعشان باید کلِ enum باشد.
        // اگر روزی وضعیتی به enum اضافه شود و به هیچ پارتیشنی نرود، همین‌جا
        // قرمز می‌شود — نه ماه‌ها بعد با یک سفارشِ گم‌شده.
        $covered = array_merge(
            [
                Order::STATUS_RESERVED,
                Order::STATUS_PROCESSING,
                Order::STATUS_AWAITING_WEIGHT_CORRECTION,
                Order::STATUS_SHIPPED,
                Order::STATUS_PARTIALLY_DELIVERED,
                Order::STATUS_RETURNED_TO_ORIGIN,
            ],
            [Order::STATUS_DELIVERED],
            [Order::STATUS_CANCELLED],
        );

        $this->assertEqualsCanonicalizing($this->enumValues(), $covered);
    }
}
