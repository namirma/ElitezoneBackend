<?php

namespace Tests\Feature\Notification;

use App\Events\Order\OrderPaid;
use App\Models\SmsTemplate;
use App\Sms\EventCatalog;
use App\Sms\PlaceholderCatalog;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * فهرستِ seeder را با کد مقایسه می‌کند — در هر دو جهت — تا پنل هرگز پیامکی را
 * بدونِ محرک، یا محرکی را که به کد اشاره نمی‌کند، نشان ندهد.
 *
 * چرا این تست: تا ۲۴ شهریور محرکِ هر built-in فقط یک شرحِ آزاد بود و `event`
 * خالی؛ ادمین برای «پرداخت سفارش» می‌دید «ارسال‌کننده: SendOrderPaymentNotification»
 * ولی نه رویدادی، نه زمان‌بندی‌ای. متنِ آزاد را هیچ چیزی با کد نمی‌سنجید.
 */
class SmsCatalogCompletenessTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * کلیدهایی که با متغیر به `::template()` می‌رسند و grepِ لیترال نمی‌بیندشان —
     * هر ورودی باید هنوز در کد باشد (ستونِ دوم: فایل، ستونِ سوم: عبارتِ حاضر).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const INDIRECT = [
        'overweight_supplier_request' => ['app/Actions/Order/RequestOverweightApprovalAction.php', "'overweight_supplier_request'"],
        'overweight_buyer_request' => ['app/Actions/Order/RequestOverweightApprovalAction.php', "'overweight_buyer_request'"],
        'overweight_rejected' => ['app/Actions/Order/RequestOverweightApprovalAction.php', "'overweight_rejected'"],
        'ticket_admin_replied' => ['app/Services/TicketService.php', "'ticket_admin_replied'"],
        'ticket_resolved' => ['app/Services/TicketService.php', "'ticket_resolved'"],
        'ticket_closed' => ['app/Services/TicketService.php', "'ticket_closed'"],
        'transaction_confirmed' => ['app/Listeners/Financial/SendTransactionNotification.php', 'transaction_confirmed'],
        'transaction_rejected' => ['app/Listeners/Financial/SendTransactionNotification.php', 'transaction_rejected'],
        'order_cancelled_refunded' => ['app/Listeners/Order/SendOrderCancellationNotification.php', 'order_cancelled_refunded'],
        'order_cancel_escalated' => ['app/Actions/Order/OrderCancelRequestAction.php', "'order_cancel_escalated'"],
        'order_cancel_admin_approved' => ['app/Actions/Order/OrderCancelRequestAction.php', "'order_cancel_admin_approved'"],
        'order_cancel_admin_rejected' => ['app/Actions/Order/OrderCancelRequestAction.php', "'order_cancel_admin_rejected'"],
        'order_cancel_requested' => ['app/Actions/Order/OrderCancelRequestAction.php', "'order_cancel_requested'"],
        'order_cancel_accepted' => ['app/Actions/Order/OrderCancelRequestAction.php', "'order_cancel_accepted'"],
        'order_cancel_rejected' => ['app/Actions/Order/OrderCancelRequestAction.php', "'order_cancel_rejected'"],
        'reserve_extension_proposed' => ['app/Actions/Order/ReserveExtensionAction.php', "'reserve_extension_proposed'"],
        'reserve_extension_accepted' => ['app/Actions/Order/ReserveExtensionAction.php', "'reserve_extension_accepted'"],
        'reserve_extension_rejected' => ['app/Actions/Order/ReserveExtensionAction.php', "'reserve_extension_rejected'"],
        'price_change_expired' => ['app/Console/Commands/ExpirePriceChangeRequests.php', "'price_change_expired'"],
        'product_change_approved' => ['app/Services/Product/ProductChangeRequestService.php', "'product_change_approved'"],
        'product_change_rejected' => ['app/Services/Product/ProductChangeRequestService.php', "'product_change_rejected'"],
        'coupon_funding_requested' => ['app/Services/Marketing/CouponFunderService.php', "'coupon_funding_requested'"],
        'coupon_funding_accepted' => ['app/Services/Marketing/CouponFunderService.php', "'coupon_funding_accepted'"],
        'coupon_funding_declined' => ['app/Services/Marketing/CouponFunderService.php', "'coupon_funding_declined'"],
        'order_paid_buyer' => ['app/Listeners/Order/SendOrderPaymentNotification.php', "'order_paid_buyer'"],
        'order_paid_supplier' => ['app/Listeners/Order/SendSupplierOrderNotification.php', "'order_paid_supplier'"],
        'order_cancelled' => ['app/Listeners/Order/SendOrderCancellationNotification.php', "'order_cancelled'"],
        'order_placed' => ['app/Listeners/Order/SendOrderPlacedNotification.php', "'order_placed'"],
        'wallet_funded' => ['app/Listeners/Financial/SendWalletFundingNotification.php', "'wallet_funded'"],
        'elite_yar_sms_invitation' => ['app/Http/Controllers/Api/User/EliteYARInviteController.php', "'elite_yar_sms_invitation'"],
        'employer_invitation' => ['app/Http/Controllers/Api/User/EmployerInviteController.php', "'employer_invitation'"],
    ];

    /** کلیدهایی که عمداً فرستنده ندارند — فهرست باید با `KIND_NONE` در seeder یکی باشد. */
    private const UNSENT = ['delivery_expiring'];

    #[Test]
    public function every_lang_key_has_a_catalog_row_with_a_structured_trigger(): void
    {
        $catalog = SmsTemplateSeeder::catalog();

        foreach (array_keys(trans('sms')) as $key) {
            $this->assertArrayHasKey($key, $catalog, "کلیدِ lang «{$key}» در seeder ردیف ندارد.");

            [$kind, $target] = array_pad((array) ($catalog[$key][4] ?? []), 2, null);
            $this->assertContains($kind, SmsTemplate::KINDS, "محرکِ «{$key}» نوعِ معتبری ندارد.");

            if ($kind === SmsTemplate::KIND_NONE) {
                $this->assertContains($key, self::UNSENT, "«{$key}» بدونِ فرستنده علامت خورده ولی در فهرستِ UNSENT نیست.");

                continue;
            }

            $this->assertNotEmpty($target, "محرکِ «{$key}» فرستنده/رویداد ندارد.");

            if ($kind === SmsTemplate::KIND_EVENT) {
                $this->assertTrue(EventCatalog::has($target), "رویدادِ «{$key}» ({$target}) در EventCatalog نیست.");
            }

            if ($kind === SmsTemplate::KIND_SCHEDULED) {
                $this->assertNotEmpty($catalog[$key][4][2] ?? null, "کرونِ «{$key}» زمان‌بندی ندارد.");
                $name = explode(' ', (string) $target)[0];
                $this->assertStringContainsString($name, file_get_contents(base_path('routes/console.php')), "«{$name}» در routes/console.php زمان‌بندی نشده.");
            }
        }

        foreach (array_keys($catalog) as $key) {
            $this->assertTrue(trans()->has("sms.{$key}"), "ردیفِ seeder «{$key}» متنِ lang ندارد.");
        }
    }

    #[Test]
    public function every_template_call_in_app_points_at_a_catalogued_key(): void
    {
        $catalog = SmsTemplateSeeder::catalog();
        $seen = [];

        foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $file) {
            preg_match_all("/::template\\(\\s*[^,]+,\\s*'([A-Za-z_][A-Za-z0-9_]*)'/", $file->getContents(), $m);

            foreach ($m[1] as $key) {
                $seen[$key] = true;
                $this->assertArrayHasKey($key, $catalog, "«{$key}» در {$file->getRelativePathname()} فرستاده می‌شود ولی در seeder نیست.");
            }
        }

        foreach (self::INDIRECT as $key => [$path, $needle]) {
            $seen[$key] = true;
            $this->assertArrayHasKey($key, $catalog);
            $this->assertStringContainsString($needle, file_get_contents(base_path($path)), "«{$key}» دیگر در {$path} فرستاده نمی‌شود — فهرستِ INDIRECT یا seeder کهنه است.");
        }

        // جهتِ برعکس: هر ردیفِ seeder یا در کد فرستاده می‌شود یا صریحاً «بدونِ فرستنده» است.
        foreach (array_keys($catalog) as $key) {
            $this->assertTrue(isset($seen[$key]) || in_array($key, self::UNSENT, true), "ردیفِ seeder «{$key}» هیچ فرستنده‌ای در app/ ندارد.");
        }
    }

    /**
     * پنل هر جانگهدارِ اعلام‌نشده را رد می‌کند (`ValidatesSmsTemplate::assertPlaceholdersKnown`). بازنویسیِ دستورالعملِ مدیریت
     * `:first_name` را به یازده متن اضافه کرد و فهرستِ seeder نه — ادمینی که متنِ پیش‌فرض را بی‌تغییر ذخیره می‌کرد ۴۲۲ می‌گرفت.
     */
    #[Test]
    public function every_placeholder_a_default_text_uses_is_declared_so_the_panel_can_save_it(): void
    {
        $catalog = SmsTemplateSeeder::catalog();

        foreach ((array) trans('sms', [], 'fa') as $key => $body) {
            if (! is_string($body) || ! isset($catalog[$key])) {
                continue;
            }

            $undeclared = array_diff(SmsTemplate::placeholdersIn($body), $catalog[$key][3]);
            $this->assertSame([], array_values($undeclared), "«{$key}» از جانگهدارِ اعلام‌نشده استفاده می‌کند.");
        }
    }

    /** مدیریت، دورِ ششم، پرسشِ ۸: مبلغِ خریدار (‎|ΔPOO|‎) حتی با ویرایشِ ادمین هم نباید به تأمین‌کننده برسد. */
    #[Test]
    public function overweight_messages_cannot_carry_the_buyers_amount(): void
    {
        $catalog = SmsTemplateSeeder::catalog();

        foreach (['overweight_supplier_request', 'overweight_rejected'] as $key) {
            $this->assertNotContains('amount', $catalog[$key][3], "«{$key}» به تأمین‌کننده می‌رود.");
        }
    }

    #[Test]
    public function every_placeholder_in_the_catalog_has_a_meaning(): void
    {
        foreach (SmsTemplateSeeder::catalog() as $key => $meta) {
            foreach ($meta[3] as $name) {
                $this->assertTrue(PlaceholderCatalog::knows($name), "جانگهدارِ :{$name} در «{$key}» معنی ندارد.");
            }
        }

        foreach (EventCatalog::all() as $event => $meta) {
            foreach ($meta['placeholders'] as $name) {
                $this->assertTrue(PlaceholderCatalog::knows($name), "جانگهدارِ :{$name} در {$event} معنی ندارد.");
            }
            foreach (array_keys($meta['audiences']) as $audience) {
                $this->assertNotEmpty($meta['resolutions'][$audience] ?? null, "مخاطبِ {$audience} در {$event} نمی‌گوید گیرنده چطور پیدا می‌شود.");
            }
        }
    }

    #[Test]
    public function seeding_stores_the_trigger_and_the_resource_exposes_it(): void
    {
        Cache::flush();
        (new SmsTemplateSeeder)->run();

        $paid = SmsTemplate::query()->where(SmsTemplate::KEY, 'order_paid_supplier')->firstOrFail();
        $this->assertSame(SmsTemplate::KIND_EVENT, $paid->{SmsTemplate::TRIGGER_KIND});
        $this->assertSame(OrderPaid::class, $paid->{SmsTemplate::EVENT});
        $this->assertNull($paid->{SmsTemplate::SENDER});

        $cart = SmsTemplate::query()->where(SmsTemplate::KEY, 'cart_expiring')->firstOrFail();
        $this->assertSame(SmsTemplate::KIND_SCHEDULED, $cart->{SmsTemplate::TRIGGER_KIND});
        $this->assertSame('carts:warn-expiring', $cart->{SmsTemplate::SENDER});
        $this->assertSame('هر ۵ دقیقه', $cart->{SmsTemplate::SCHEDULE});

        $this->assertSame(SmsTemplate::KIND_NONE, SmsTemplate::query()->where(SmsTemplate::KEY, 'delivery_expiring')->value(SmsTemplate::TRIGGER_KIND));

        $details = $paid->placeholderDetails();
        $this->assertSame('order_number', $details[0]['name']);
        $this->assertNotEmpty($details[0]['meaning']);
        $this->assertNotEmpty($details[0]['example']);

        // `code` در تیکت کد پیگیری است، نه کد ورود
        $ticket = SmsTemplate::query()->where(SmsTemplate::KEY, 'ticket_closed')->firstOrFail();
        $this->assertStringContainsString('تیکت', $ticket->placeholderDetails()[0]['meaning']);
    }
}
