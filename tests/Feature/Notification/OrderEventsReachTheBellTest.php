<?php

namespace Tests\Feature\Notification;

use App\Events\Order\OrderCancelled;
use App\Events\Order\OrderPaid;
use App\Events\Order\OrderPlaced;
use App\Jobs\Notification\SendPushJob;
use App\Jobs\Notification\SendSmsJob;
use App\Listeners\Order\SendOrderCancellationNotification;
use App\Listeners\Order\SendOrderPaymentNotification;
use App\Listeners\Order\SendOrderPlacedNotification;
use App\Models\Order;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * رویدادهای سفارش باید **هم** پیامک بدهند و **هم** ردیفِ اعلانِ درون‌برنامه‌ای.
 *
 * تا پیش از این هر شش لیسنر فقط `SendSmsJob` می‌زدند. یعنی زنگولهٔ اعلانِ
 * داخل پنل دربارهٔ سفارش‌ها همیشه خالی بود، و چون پوش از روی همان ردیف‌ها
 * ساخته می‌شود، پوش هم عملاً چیزی برای حمل نداشت — فقط خوش‌آمدگویی و دعوتِ
 * الیت‌یار. این تست هر دو کانال را با هم می‌سنجد، چون حذفِ یکی‌شان هیچ تستِ
 * دیگری را قرمز نمی‌کند.
 */
class OrderEventsReachTheBellTest extends TestCase
{
    use DatabaseTransactions;

    private function assertBothChannels(callable $fire, User $recipient): void
    {
        Queue::fake();

        $before = UserNotification::where(UserNotification::USER_ID, $recipient->id)->count();

        $fire();

        Queue::assertPushed(SendSmsJob::class);
        Queue::assertPushed(SendPushJob::class);

        $this->assertSame(
            $before + 1,
            UserNotification::where(UserNotification::USER_ID, $recipient->id)->count(),
        );
    }

    #[Test]
    public function an_order_placed_notifies_both_channels(): void
    {
        $order = Order::factory()->create();

        $this->assertBothChannels(
            fn () => (new SendOrderPlacedNotification)->handle(new OrderPlaced($order)),
            $order->placer,
        );
    }

    #[Test]
    public function an_order_payment_notifies_both_channels(): void
    {
        $order = Order::factory()->create();

        $this->assertBothChannels(
            fn () => (new SendOrderPaymentNotification)->handle(new OrderPaid($order)),
            $order->placer,
        );
    }

    #[Test]
    public function an_order_cancellation_notifies_both_channels(): void
    {
        $order = Order::factory()->create();

        $this->assertBothChannels(
            fn () => (new SendOrderCancellationNotification)->handle(new OrderCancelled($order)),
            $order->placer,
        );
    }

    #[Test]
    public function the_notification_body_is_the_same_text_as_the_sms(): void
    {
        // عنوان و متن نباید جای هم بنشینند: متن از lang/fa/sms.php می‌آید و
        // عنوان از lang/fa/notifications.php. اگر کسی جابه‌جا کند، کاربر یک
        // نوتیفیکیشن با عنوانِ «سفارش شما با شماره…» و متنِ «ثبت سفارش» می‌گیرد.
        Queue::fake();
        $order = Order::factory()->create();

        (new SendOrderPlacedNotification)->handle(new OrderPlaced($order));

        $row = UserNotification::where(UserNotification::USER_ID, $order->placer->id)
            ->latest(UserNotification::ID)
            ->first();

        $this->assertSame(__('notifications.order_placed_subject'), $row->{UserNotification::SUBJECT});
        $this->assertStringContainsString($order->order_number, (string) $row->{UserNotification::BODY});
    }
}
