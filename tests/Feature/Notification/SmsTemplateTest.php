<?php

namespace Tests\Feature\Notification;

use App\Events\Order\OrderPaid;
use App\Events\User\CustomerSegmentChanged;
use App\Jobs\Notification\SendSmsJob;
use App\Listeners\Notification\SendCustomSmsForEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifier;
use App\Services\SmsService;
use App\Sms\EventCatalog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * پیامک به‌عنوان رکورد: متنِ ویرایش‌شده‌ی ادمین، خاموش‌بودن، شرط‌ها، رویدادِ
 * سفارشی و لاگِ ارسال — همه از یک نقطه (`SmsTemplate::render` → `SendSmsJob`).
 */
class SmsTemplateTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function row(string $key, array $overrides = []): SmsTemplate
    {
        return SmsTemplate::create(array_merge([
            SmsTemplate::KEY => $key,
            SmsTemplate::TITLE => $key,
            SmsTemplate::AUDIENCE => SmsTemplate::AUDIENCE_ANY,
            SmsTemplate::BODY => __("sms.{$key}"),
            SmsTemplate::PLACEHOLDERS => SmsTemplate::placeholdersIn(__("sms.{$key}")),
            SmsTemplate::IS_ACTIVE => true,
        ], $overrides));
    }

    // ── رندر ────────────────────────────────────────────────────────────────

    #[Test]
    public function without_a_row_the_lang_text_is_used(): void
    {
        $this->assertSame(0, SmsTemplate::where(SmsTemplate::KEY, 'order_placed')->count());

        $this->assertSame(
            __('sms.order_placed', ['order_number' => 'EZ-1']),
            SmsTemplate::render('order_placed', ['order_number' => 'EZ-1']),
        );
    }

    #[Test]
    public function an_unknown_key_with_no_row_renders_nothing(): void
    {
        $this->assertNull(SmsTemplate::render('no_such_key_'.Str::random(6)));
    }

    #[Test]
    public function the_edited_body_wins_over_the_lang_text(): void
    {
        $this->row('order_placed', [SmsTemplate::BODY => 'متنِ تازه برای :order_number']);

        $this->assertSame('متنِ تازه برای EZ-2', SmsTemplate::render('order_placed', ['order_number' => 'EZ-2']));
    }

    #[Test]
    public function longer_placeholders_are_replaced_before_their_prefix(): void
    {
        // `:order_number` نباید توسط `:order` نصفه جایگزین شود.
        $this->assertSame('X / 9', SmsTemplate::interpolate(':order_number / :order', ['order' => '9', 'order_number' => 'X']));
    }

    #[Test]
    public function an_inactive_template_is_not_sent_but_is_logged(): void
    {
        $this->row('order_placed', [SmsTemplate::IS_ACTIVE => false]);

        $this->assertNull(SmsTemplate::render('order_placed', ['order_number' => 'EZ-3']));

        $gateway = Mockery::mock(SmsService::class);
        $gateway->shouldNotReceive('send');

        (new SendSmsJob('09120000001', '', 'order_placed', ['order_number' => 'EZ-3']))->handle($gateway);

        $this->assertDatabaseHas(SmsLog::TABLE, [
            SmsLog::MOBILE => '09120000001',
            SmsLog::TEMPLATE_KEY => 'order_placed',
            SmsLog::STATUS => SmsLog::STATUS_SKIPPED_INACTIVE,
        ]);
    }

    #[Test]
    public function the_bell_still_gets_the_text_when_the_sms_is_off(): void
    {
        Queue::fake();
        $this->row('order_placed', [SmsTemplate::IS_ACTIVE => false, SmsTemplate::BODY => 'زنگوله :order_number']);
        $user = User::factory()->create();

        Notifier::template($user, 'موضوع', 'order_placed', ['order_number' => 'EZ-4']);

        $this->assertSame('زنگوله EZ-4', UserNotification::where(UserNotification::USER_ID, $user->id)->value(UserNotification::BODY));
        // پیامک به صف می‌رود و در لحظه‌ی اجرا (نه dispatch) ردِ خاموش می‌خورد.
        Queue::assertPushed(SendSmsJob::class);
    }

    #[Test]
    public function editing_a_row_flushes_the_cache(): void
    {
        $row = $this->row('order_placed', [SmsTemplate::BODY => 'اول']);
        $this->assertSame('اول', SmsTemplate::render('order_placed'));

        $row->update([SmsTemplate::BODY => 'دوم']);

        $this->assertSame('دوم', SmsTemplate::render('order_placed'));
    }

    // ── شرط‌ها ──────────────────────────────────────────────────────────────

    #[Test]
    public function the_roles_condition_blocks_a_user_without_the_role_and_ignores_strangers(): void
    {
        $this->row('order_placed', [SmsTemplate::CONDITIONS => [SmsTemplate::COND_ROLES => ['supplier']]]);

        $engineer = User::factory()->create();
        $engineer->addRole(Role::firstOrCreate(['name' => 'engineer'], ['display_name' => 'Engineer']));
        $supplier = User::factory()->create();
        $supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));

        $this->assertNull(SmsTemplate::render('order_placed', [], $engineer->mobile));
        $this->assertNotNull(SmsTemplate::render('order_placed', [], $supplier->mobile));
        // شماره‌ای که کاربر نیست (راننده، دعوت‌شده) از شرطِ نقش معاف است.
        $this->assertNotNull(SmsTemplate::render('order_placed', [], '09999999999'));
    }

    #[Test]
    public function the_min_amount_condition_reads_the_formatted_amount(): void
    {
        $this->row('transaction_confirmed', [SmsTemplate::CONDITIONS => [SmsTemplate::COND_MIN_AMOUNT => 2_000_000]]);

        $this->assertNull(SmsTemplate::render('transaction_confirmed', ['type' => 'واریز', 'amount' => '1,200,000']));
        $this->assertNotNull(SmsTemplate::render('transaction_confirmed', ['type' => 'واریز', 'amount' => '2,000,000']));
        // بدونِ پارامترِ amount شرط بی‌اثر است — نه ردِ کورکورانه.
        $this->assertNotNull(SmsTemplate::render('transaction_confirmed', ['type' => 'واریز']));
    }

    #[Test]
    public function quiet_hours_delay_the_job_until_the_window_ends(): void
    {
        $this->row('order_placed', [SmsTemplate::CONDITIONS => [SmsTemplate::COND_QUIET_HOURS => ['from' => '22:00', 'to' => '08:00']]]);

        $tz = SmsTemplate::QUIET_HOURS_TIMEZONE;

        $this->assertNull(SmsTemplate::quietHoursDelay('order_placed', Carbon::parse('2026-09-14 12:00', $tz)));

        $lateNight = SmsTemplate::quietHoursDelay('order_placed', Carbon::parse('2026-09-14 23:30', $tz));
        $this->assertSame('2026-09-15 08:00', $lateNight?->format('Y-m-d H:i'));

        $earlyMorning = SmsTemplate::quietHoursDelay('order_placed', Carbon::parse('2026-09-15 06:00', $tz));
        $this->assertSame('2026-09-15 08:00', $earlyMorning?->format('Y-m-d H:i'));

        // پنجره‌ی درونِ یک روز
        $this->row('cart_expiring', [SmsTemplate::CONDITIONS => [SmsTemplate::COND_QUIET_HOURS => ['from' => '13:00', 'to' => '14:00']]]);
        $this->assertSame('2026-09-14 14:00', SmsTemplate::quietHoursDelay('cart_expiring', Carbon::parse('2026-09-14 13:30', $tz))?->format('Y-m-d H:i'));
        $this->assertNull(SmsTemplate::quietHoursDelay('cart_expiring', Carbon::parse('2026-09-14 15:00', $tz)));
    }

    #[Test]
    public function the_dispatch_helper_carries_the_quiet_hours_delay(): void
    {
        Queue::fake();
        $this->row('order_placed', [SmsTemplate::CONDITIONS => [SmsTemplate::COND_QUIET_HOURS => ['from' => '22:00', 'to' => '08:00']]]);
        Carbon::setTestNow(Carbon::parse('2026-09-14 23:30', SmsTemplate::QUIET_HOURS_TIMEZONE));

        try {
            SendSmsJob::template('09120000002', 'order_placed', ['order_number' => 'EZ-5']);
            Queue::assertPushed(SendSmsJob::class, fn (SendSmsJob $job) => $job->delay !== null);

            Carbon::setTestNow(Carbon::parse('2026-09-14 12:00', SmsTemplate::QUIET_HOURS_TIMEZONE));
            SendSmsJob::template('09120000003', 'order_placed', ['order_number' => 'EZ-6']);
            Queue::assertPushed(SendSmsJob::class, fn (SendSmsJob $job) => $job->delay === null);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function a_protected_template_ignores_conditions_even_if_the_row_carries_them(): void
    {
        $row = $this->row('loginMessage', [SmsTemplate::CONDITIONS => [SmsTemplate::COND_ROLES => ['supplier']]]);
        $row->{SmsTemplate::IS_PROTECTED} = true;
        $row->save();

        $engineer = User::factory()->create();

        $this->assertNotNull(SmsTemplate::render('loginMessage', ['code' => '123456'], $engineer->mobile));
    }

    // ── قالبِ سفارشی روی رویداد ─────────────────────────────────────────────

    #[Test]
    public function a_custom_template_reaches_only_its_audience(): void
    {
        Queue::fake();

        $buyer = User::factory()->create();
        $supplier = User::factory()->create();

        $order = Order::create([
            Order::PLACER_USER_ID => $buyer->id,
            Order::ORDER_NUMBER => 'CUST-'.Str::random(8),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::FINAL_AMOUNT => 1_000_000,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);
        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $order->id,
            OrderItem::SUPPLIER_USER_ID => $supplier->id,
        ]);

        SmsTemplate::create([
            SmsTemplate::KEY => 'custom_'.Str::lower(Str::random(8)),
            SmsTemplate::TITLE => 'خبر به تأمین‌کننده',
            SmsTemplate::AUDIENCE => SmsTemplate::AUDIENCE_SUPPLIER,
            SmsTemplate::BODY => 'سفارش :order_number پرداخت شد — :name',
            SmsTemplate::EVENT => OrderPaid::class,
            SmsTemplate::IS_CUSTOM => true,
            SmsTemplate::IS_ACTIVE => true,
        ]);

        (new SendCustomSmsForEvent)->handle(new OrderPaid($order));

        Queue::assertPushed(SendSmsJob::class, 1);
        Queue::assertPushed(SendSmsJob::class, fn (SendSmsJob $job) => $this->jobTargets($job, $supplier->mobile));
        Queue::assertNotPushed(SendSmsJob::class, fn (SendSmsJob $job) => $this->jobTargets($job, $buyer->mobile));
    }

    #[Test]
    public function an_inactive_custom_template_sends_nothing(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        SmsTemplate::create([
            SmsTemplate::KEY => 'custom_'.Str::lower(Str::random(8)),
            SmsTemplate::TITLE => 'خاموش',
            SmsTemplate::AUDIENCE => SmsTemplate::AUDIENCE_ANY,
            SmsTemplate::BODY => 'x',
            SmsTemplate::EVENT => CustomerSegmentChanged::class,
            SmsTemplate::IS_CUSTOM => true,
            SmsTemplate::IS_ACTIVE => false,
        ]);

        (new SendCustomSmsForEvent)->handle(new CustomerSegmentChanged($user, 'a', 'b'));

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_generic_listener_is_registered_for_every_catalogued_event(): void
    {
        foreach (EventCatalog::events() as $event) {
            $this->assertTrue(
                Event::hasListeners($event),
                "{$event} در فهرست هست ولی هیچ لیسنری ندارد."
            );

            $listeners = array_map(
                fn ($l) => is_string($l) ? $l : (is_object($l) ? $l::class : ''),
                Event::getRawListeners()[$event] ?? [],
            );

            $this->assertContains(SendCustomSmsForEvent::class, $listeners, "{$event} به SendCustomSmsForEvent وصل نیست.");
        }
    }

    // ── لاگِ ارسال ──────────────────────────────────────────────────────────

    #[Test]
    public function every_outcome_of_the_gateway_is_logged(): void
    {
        // ۱) بدونِ پیکربندی (phpunit.xml) → unconfigured
        app(SmsService::class)->send('09120000010', 'سلام', 'order_placed');
        $this->assertDatabaseHas(SmsLog::TABLE, ['mobile' => '09120000010', 'template_key' => 'order_placed', 'status' => SmsLog::STATUS_UNCONFIGURED]);

        config()->set('services.tsms.link', 'https://sms.test/send');
        config()->set('services.tsms.number', '3000');
        config()->set('services.tsms.username', 'u');
        config()->set('services.tsms.password', 'p');

        // ۲) خارج از allowlist → skipped_allowlist، بدونِ HTTP
        config()->set('services.tsms.dev_allowlist', ['09130000000']);
        // استابِ آدرس‌دار، نه catch-all: استابِ بی‌آدرس در همه‌ی fakeهای بعدی هم اول match می‌شود.
        Http::fake(['https://sms.test/*' => Http::response('ok', 200)]);
        app(SmsService::class)->send('09120000011', 'سلام', 'order_placed');
        Http::assertNothingSent();
        $this->assertDatabaseHas(SmsLog::TABLE, ['mobile' => '09120000011', 'status' => SmsLog::STATUS_SKIPPED_ALLOWLIST]);

        // ۳) allowlist + پاسخِ 200 → sent
        Http::fake(['https://sms.test/*' => Http::response('ok', 200)]);
        app(SmsService::class)->send('09130000000', 'سلام', 'order_placed');
        $this->assertDatabaseHas(SmsLog::TABLE, ['mobile' => '09130000000', 'status' => SmsLog::STATUS_SENT]);

        // ۴) allowlist + پاسخِ 500 → failed با متنِ خطا (آدرسِ تازه: استابِ قبلی روی sms.test مقدم است)
        config()->set('services.tsms.link', 'https://sms-down.test/send');
        Http::fake(['https://sms-down.test/*' => Http::response('boom', 500)]);
        app(SmsService::class)->send('09130000000', 'خطا', 'order_placed');
        $this->assertDatabaseHas(SmsLog::TABLE, ['mobile' => '09130000000', 'body' => 'خطا', 'status' => SmsLog::STATUS_FAILED, 'error' => 'HTTP 500']);
    }

    #[Test]
    public function a_raw_message_job_still_works_and_logs_without_a_key(): void
    {
        $gateway = Mockery::mock(SmsService::class);
        $gateway->shouldReceive('send')->once()->with('09120000020', 'متنِ خام', null)->andReturn(true);

        (new SendSmsJob('09120000020', 'متنِ خام'))->handle($gateway);
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
