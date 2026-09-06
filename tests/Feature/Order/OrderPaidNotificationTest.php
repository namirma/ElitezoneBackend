<?php

namespace Tests\Feature\Order;

use App\Events\Order\OrderPaid;
use App\Jobs\Notification\SendSmsJob;
use App\Listeners\Order\SendOrderPaymentNotification;
use App\Listeners\Order\SendSupplierOrderNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Providers\EventServiceProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * اعلانِ پرداخت — هم به خریدار، هم به تأمین‌کننده.
 *
 * تا پیش از این `OrderPaid` **فقط** از `PayOrderAction` dispatch می‌شد، و آن اکشن
 * صفر فراخوانی دارد (داربستِ مسیرِ درگاه که هرگز وصل نشد). یعنی رویداد در هیچ
 * مسیرِ زنده‌ای شلیک نمی‌شد و `SendOrderPaymentNotification` — که سال‌ها ثبت شده
 * بود — هرگز اجرا نشد. نه تأمین‌کننده خبر می‌گرفت، نه خودِ خریدار.
 *
 * این تست هر دو نیمه را پین می‌کند: اینکه رویداد از مسیرهای واقعیِ پرداخت شلیک
 * می‌شود، و اینکه هر listener پیامکِ درست را به شخصِ درست می‌فرستد.
 */
class OrderPaidNotificationTest extends TestCase
{
    use DatabaseTransactions;

    private function makePaidOrder(?User $supplier = null): array
    {
        $buyer = User::factory()->create();
        $supplier ??= User::factory()->create();

        $order = Order::create([
            Order::PLACER_USER_ID => $buyer->id,
            Order::ORDER_NUMBER => 'PAID-'.Str::random(10),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::FINAL_AMOUNT => 1_000_000,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $order->id,
            OrderItem::SUPPLIER_USER_ID => $supplier->id,
        ]);

        return [$order, $buyer, $supplier];
    }

    #[Test]
    public function the_supplier_is_told_an_order_entered_their_queue(): void
    {
        Queue::fake();

        [$order, , $supplier] = $this->makePaidOrder();

        (new SendSupplierOrderNotification)->handle(new OrderPaid($order));

        Queue::assertPushed(
            SendSmsJob::class,
            fn (SendSmsJob $job) => $this->jobTargets($job, $supplier->mobile)
        );
    }

    #[Test]
    public function the_buyer_still_gets_their_own_confirmation(): void
    {
        Queue::fake();

        [$order, $buyer] = $this->makePaidOrder();

        (new SendOrderPaymentNotification)->handle(new OrderPaid($order));

        Queue::assertPushed(
            SendSmsJob::class,
            fn (SendSmsJob $job) => $this->jobTargets($job, $buyer->mobile)
        );
    }

    #[Test]
    public function a_supplier_with_several_lines_on_one_order_is_texted_once(): void
    {
        Queue::fake();

        [$order, , $supplier] = $this->makePaidOrder();

        // ردیفِ دوم از همان تأمین‌کننده — سبدِ دو-سایزِ میلگرد، حالتِ کاملاً عادی.
        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $order->id,
            OrderItem::SUPPLIER_USER_ID => $supplier->id,
        ]);

        (new SendSupplierOrderNotification)->handle(new OrderPaid($order->fresh()));

        Queue::assertPushed(SendSmsJob::class, 1);
    }

    #[Test]
    public function an_order_with_no_supplier_is_skipped_without_throwing(): void
    {
        Queue::fake();

        $order = Order::create([
            Order::PLACER_USER_ID => User::factory()->create()->id,
            Order::ORDER_NUMBER => 'NOSUP-'.Str::random(10),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::FINAL_AMOUNT => 1_000_000,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);

        (new SendSupplierOrderNotification)->handle(new OrderPaid($order));

        Queue::assertNothingPushed();
    }

    #[Test]
    public function both_listeners_are_registered_for_the_event(): void
    {
        // بدون این، افزودنِ listener تازه بی‌صدا از EventServiceProvider جا می‌ماند.
        // مستقیم روی نگاشتِ provider خوانده می‌شود: getListeners() به Closureهای
        // پیچیده‌شده تبدیل می‌کند و کلاسِ اصلی از آن‌ها قابل بازیابی نیست.
        $listen = (new \ReflectionClass(EventServiceProvider::class))
            ->getDefaultProperties()['listen'];

        $this->assertContains(SendOrderPaymentNotification::class, $listen[OrderPaid::class]);
        $this->assertContains(SendSupplierOrderNotification::class, $listen[OrderPaid::class]);
    }

    #[Test]
    public function the_live_payment_actions_dispatch_the_event(): void
    {
        // گاردِ اصلی: رویداد باید از مسیرهای واقعیِ پرداخت شلیک شود، نه فقط از
        // PayOrderActionِ بدونِ فراخوان. در سطحِ سورس چک می‌شود چون اجرای کاملِ
        // این دو اکشن به کیف‌پول/سند/باسکول وابسته است و اینجا موضوع فقط سیم‌کشی است.
        foreach ([
            'app/Actions/Financial/ProcessPreScalePaymentAction.php',
            'app/Actions/Financial/ProcessReservationCompletionAction.php',
        ] as $file) {
            $this->assertStringContainsString(
                'OrderPaid::dispatch',
                (string) file_get_contents(base_path($file)),
                "{$file} باید OrderPaid را dispatch کند، وگرنه هیچ اعلانِ پرداختی نمی‌رود."
            );
        }
    }

    private function jobTargets(SendSmsJob $job, string $mobile): bool
    {
        foreach ((array) $job as $value) {
            if (is_string($value) && $value === $mobile) {
                return true;
            }
        }

        return false;
    }
}
