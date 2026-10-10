<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Invoice\InvoiceDataBuilder;
use App\Support\Money;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * واحدِ پول به‌ازای هر اپ (علی ۱۴۰۵/۰۷/۱۹): سایت، پنلِ تأمین‌کننده و بک‌آفیس هرکدام ریال یا تومان،
 * از بک‌آفیس. داده و API ریال می‌مانند؛ متن‌های بک‌اند که مبلغ دارند با واحدِ اپِ خواننده نوشته می‌شوند
 * (هدرِ `X-Client-App` برای پاسخ‌ها، پنلِ گیرنده برای پیامک)، فاکتورِ خریدار با واحدِ سایت.
 */
class MoneyUnitTest extends TestCase
{
    use DatabaseTransactions, GrantsAdminPermissions;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::whereIn(Setting::KEY, array_map(fn ($a) => Money::settingKey($a), Money::APPS))->delete();
        Cache::forget(Setting::CACHE_KEY);
    }

    private function unit(string $app, string $unit): void
    {
        Setting::updateOrCreate([Setting::KEY => Money::settingKey($app)], [Setting::VALUE => $unit]);
        Cache::forget(Setting::CACHE_KEY);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($admin);

        return $admin;
    }

    // ── قاعده‌ی تبدیل ─────────────────────────────────────────────────────────

    #[Test]
    public function toman_is_rials_over_ten_exact_with_one_decimal_only_when_needed(): void
    {
        $this->assertSame('12,345', Money::amountIn(123_450, Money::TOMAN));
        $this->assertSame('12,345.5', Money::amountIn(123_455, Money::TOMAN));
        $this->assertSame('-12.5', Money::amountIn(-125, Money::TOMAN));
        $this->assertSame('200,000,000', Money::amountIn(2_000_000_000, Money::TOMAN));
        $this->assertSame('123,455', Money::amountIn(123_455, Money::RIAL));
        $this->assertSame('123,455', Money::amountIn('123455.00', Money::RIAL));
    }

    // ── تنظیمات ──────────────────────────────────────────────────────────────

    #[Test]
    public function the_three_units_default_to_rial_are_admin_editable_and_public(): void
    {
        $public = $this->getJson('/api/settings/public')->assertOk()->json('data');
        foreach (Money::APPS as $app) {
            $this->assertSame('rial', $public[Money::settingKey($app)]);
        }

        Sanctum::actingAs($this->admin());
        $this->putJson('/api/admin/settings', ['settings' => ['currency_unit_site' => 'dollar']])
            ->assertStatus(422)->assertJsonValidationErrors('currency_unit_site');
        $this->putJson('/api/admin/settings', ['settings' => [
            'currency_unit_site' => 'toman',
            'currency_unit_backoffice' => 'toman',
        ]])->assertOk();

        $public = $this->getJson('/api/settings/public')->json('data');
        $this->assertSame('toman', $public['currency_unit_site']);
        $this->assertSame('rial', $public['currency_unit_supplier']);
        $this->assertSame('toman', $public['currency_unit_backoffice']);
    }

    // ── پیام‌های پاسخ: واحدِ اپی که درخواست داده ───────────────────────────────

    #[Test]
    public function validation_messages_follow_the_requesting_app(): void
    {
        $this->unit(Money::APP_SITE, Money::TOMAN);
        $user = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $user->id, Wallet::BALANCE => 5_000_000, Wallet::BALANCE_HOLD => 0]);
        Sanctum::actingAs($user);
        $min = (int) config('financial.withdrawal_min');

        $site = $this->withHeader('X-Client-App', 'site')
            ->postJson('/api/profile/financial/wallet/withdraw', ['amount' => 1])->assertStatus(422)->json('errors.amount.0');
        $this->assertStringContainsString(Money::amountIn($min, Money::TOMAN).' تومان', $site);
        $this->assertStringNotContainsString('ریال', $site);

        $supplier = $this->withHeader('X-Client-App', 'supplier')
            ->postJson('/api/profile/financial/wallet/withdraw', ['amount' => 1])->json('errors.amount.0');
        $this->assertStringContainsString(number_format($min).' ریال', $supplier);

        // بدونِ هدر: ریال، واحدِ خودِ داده.
        $none = $this->withHeaders(['X-Client-App' => ''])
            ->postJson('/api/profile/financial/wallet/deposit', ['amount' => 3_000_000_000, 'payment_method' => 'shaparak'])
            ->assertStatus(422)->json('errors.amount.0');
        $this->assertStringContainsString('2,000,000,000 ریال', $none);

        $max = $this->withHeader('X-Client-App', 'site')
            ->postJson('/api/profile/financial/wallet/deposit', ['amount' => 3_000_000_000, 'payment_method' => 'shaparak'])
            ->json('errors.amount.0');
        $this->assertStringContainsString('200,000,000 تومان', $max);
    }

    // ── پیامک: پنلِ گیرنده ────────────────────────────────────────────────────

    private function langOnly(string $key): void
    {
        SmsTemplate::where(SmsTemplate::KEY, $key)->delete();
        Cache::forget(SmsTemplate::CACHE_KEY);
    }

    #[Test]
    public function sms_amounts_use_the_recipients_panel_and_conditions_still_compare_rials(): void
    {
        $this->unit(Money::APP_SITE, Money::TOMAN);
        $this->langOnly('wallet_funded');
        $this->langOnly('transaction_confirmed');

        $customer = User::factory()->create();
        $text = SmsTemplate::render('wallet_funded', ['amount' => number_format(123_455), 'tracking_line' => ''], $customer->mobile);
        $this->assertStringContainsString(enToFa('12,345.5').' تومان', (string) $text);

        $supplier = User::factory()->create();
        $supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));
        $text = SmsTemplate::render('transaction_confirmed', ['type' => 'برداشت', 'amount' => number_format(123_455)], $supplier->mobile);
        $this->assertStringContainsString(enToFa('123,455').' ریال', (string) $text);

        // شرطِ «حداقل مبلغ» با ریال سنجیده می‌شود، نه با عددِ تومانیِ نمایشی.
        $row = SmsTemplate::create([
            SmsTemplate::KEY => 'wallet_funded',
            SmsTemplate::TITLE => 'شارژ کیف پول',
            SmsTemplate::BODY => (string) __('sms.wallet_funded'),
            SmsTemplate::IS_ACTIVE => true,
        ]);
        $row->forceFill([SmsTemplate::CONDITIONS => [SmsTemplate::COND_MIN_AMOUNT => 100_000]])->save();
        Cache::forget(SmsTemplate::CACHE_KEY);
        $this->assertNotNull(SmsTemplate::render('wallet_funded', ['amount' => number_format(123_455), 'tracking_line' => ''], $customer->mobile));
    }

    #[Test]
    public function staff_sms_follow_the_back_office_unit(): void
    {
        $this->unit(Money::APP_BACKOFFICE, Money::TOMAN);
        $this->langOnly('coupon_wallet_low_admin');

        $text = SmsTemplate::render('coupon_wallet_low_admin', [
            'first_name' => 'علی', 'amount' => enToFa(number_format(50_000)), 'balance' => enToFa(number_format(1_230)),
        ], $this->admin()->mobile);

        $this->assertStringContainsString(enToFa('5,000').' تومان', (string) $text);
        $this->assertStringContainsString(enToFa('123').' تومان', (string) $text);
    }

    #[Test]
    public function an_admin_edited_text_with_a_literal_rial_keeps_rial_amounts(): void
    {
        $this->unit(Money::APP_SITE, Money::TOMAN);
        $this->langOnly('wallet_funded');
        SmsTemplate::create([
            SmsTemplate::KEY => 'wallet_funded',
            SmsTemplate::TITLE => 'شارژ کیف پول',
            SmsTemplate::BODY => 'شارژ :amount ریال',
            SmsTemplate::IS_ACTIVE => true,
        ]);
        Cache::forget(SmsTemplate::CACHE_KEY);

        $text = SmsTemplate::render('wallet_funded', ['amount' => number_format(123_455), 'tracking_line' => ''], User::factory()->create()->mobile);

        $this->assertSame('شارژ '.enToFa('123,455').' ریال', $text);
    }

    // ── فاکتور: واحدِ سایت؛ اسنادِ مالیاتی (مودیان) ریالی ───────────────────────

    #[Test]
    public function the_buyer_invoice_follows_the_site_unit_and_tax_documents_stay_in_rials(): void
    {
        $this->unit(Money::APP_SITE, Money::TOMAN);
        $order = Order::factory()->create();
        $order->forceFill([Order::FINAL_AMOUNT => 123_455])->save();
        OrderItem::factory()->create([OrderItem::ORDER_ID => $order->id]);
        $order->load(['items.productVariant.product', 'placer.profile', 'address']);

        $builder = app(InvoiceDataBuilder::class);
        $invoice = $builder->build($order, FinancialCodes::DOC_RECEIPT_OWNER, 'T-1');
        $this->assertSame(Money::TOMAN, $invoice['money_unit']);
        $html = view('pdf.partials.payable_row', $invoice)->render().view('pdf.partials.items_section', $invoice)->render();
        $this->assertStringContainsString('واحد مبالغ (تومان)', $html);
        $this->assertStringContainsString(enToFa('12,345.5'), $html);
        $this->assertStringContainsString('دوازده هزار و سیصد و چهل و پنج و پنج دهم تومان', $html);
        $this->assertStringNotContainsString('ریال', $html);

        foreach ([FinancialCodes::DOC_TAX_INVOICE_BUYER, FinancialCodes::DOC_REPORT_SUPPLIER] as $code) {
            $this->assertSame(Money::RIAL, $builder->build($order, $code, 'T-2')['money_unit'], $code);
        }
    }

    /** ردیف‌های ذخیره‌شده‌ی پیامک «:amount ریال» → «:amount :unit» می‌شوند (بقیه‌ی متن دست نمی‌خورد) و برمی‌گردند. */
    #[Test]
    public function the_data_migration_moves_stored_texts_to_the_unit_placeholder_and_back(): void
    {
        $migration = require database_path('migrations/2026_10_10_110000_sms_templates_money_unit_placeholder.php');
        $this->langOnly('wallet_funded');
        $this->langOnly('wallet_deposited_podium');
        $funded = SmsTemplate::create([
            SmsTemplate::KEY => 'wallet_funded', SmsTemplate::TITLE => 'ش', SmsTemplate::IS_ACTIVE => true,
            SmsTemplate::BODY => 'متن ادمین: :amount ریال شارژ شد.', SmsTemplate::PLACEHOLDERS => ['amount'],
        ]);
        $podium = SmsTemplate::create([
            SmsTemplate::KEY => 'wallet_deposited_podium', SmsTemplate::TITLE => 'پ', SmsTemplate::IS_ACTIVE => true,
            SmsTemplate::BODY => 'مبلغ :amount تومان واریز شد.', SmsTemplate::PLACEHOLDERS => ['amount'],
        ]);

        $migration->up();
        $this->assertSame('متن ادمین: :amount :unit شارژ شد.', $funded->fresh()->{SmsTemplate::BODY});
        $this->assertSame(['amount', 'unit'], $funded->fresh()->{SmsTemplate::PLACEHOLDERS});
        $this->assertSame('مبلغ :amount :unit واریز شد.', $podium->fresh()->{SmsTemplate::BODY});

        $migration->down();
        $this->assertSame('متن ادمین: :amount ریال شارژ شد.', $funded->fresh()->{SmsTemplate::BODY});
        $this->assertSame(['amount'], $funded->fresh()->{SmsTemplate::PLACEHOLDERS});
        $this->assertSame('مبلغ :amount تومان واریز شد.', $podium->fresh()->{SmsTemplate::BODY});
    }
}
