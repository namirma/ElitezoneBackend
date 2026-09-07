<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Financial\WalletTransactionEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * چهار گزارشِ مالیِ بک‌آفیس، و سه‌تای‌شان روی داده‌ای فیلتر می‌کردند که وجود
 * ندارد.
 *
 *   `getExpertDocuments`  → `meta->invoice_path` — در کلِ مخزن هیچ نویسنده‌ای
 *                            ندارد؛ **کلِ اندپوینت** همیشه تهی بود.
 *   `getExpertShares`     → `fee_hold` (فقط روی کیف پولِ تأمین‌کننده نوشته
 *                            می‌شود) ∧ نقشِ engineer/contractor — اشتراکشان تهی؛
 *                            و `fee_release` که هیچ نویسنده‌ای ندارد.
 *   `getSupplierDocuments`→ تبِ «مالیاتی» روی مسیرِ فیشِ تصویری فیلتر می‌کرد،
 *                            نه سندِ مودیان.
 *
 * هیچ‌کدام خطا نمی‌دادند. فهرستِ تهی از «هنوز سندی صادر نشده» قابلِ تشخیص نبود.
 */
class AdminFinancialReportsTest extends TestCase
{
    use DatabaseTransactions, GrantsAdminPermissions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($this->admin);
    }

    private function makeOrder(array $snapshot = []): Order
    {
        $order = Order::create([
            Order::PLACER_USER_ID => User::factory()->create()->id,
            Order::ORDER_NUMBER => 'FR-'.uniqid(),
            Order::STATUS => Order::STATUS_SHIPPED,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);

        if ($snapshot !== []) {
            $order->forceFill($snapshot)->save();
        }

        // ⚠️ سفارش باید **حداقل یک ردیف** داشته باشد.
        //
        // این فیکسچر قبلاً سفارشِ بی‌ردیف می‌ساخت، و چون لاراول رابطهٔ تودرتو را
        // وقتی رابطهٔ والد خالی است اصلاً حل نمی‌کند، یک eager-loadِ غلط مثل
        // `items.factory` (که روی `OrderItem` وجود ندارد — نامش `originFactory`
        // است) هرگز اجرا نمی‌شد. تست سبز می‌ماند و اندپوینت ۲۰۰ می‌داد، تا اولین
        // سفارشِ واقعی که شرطِ فیلتر را پاس کند و ۵۰۰ بگیرد. تیم فرانت همین را
        // روی dev بازتولید کرد.
        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $order->id,
            OrderItem::SUPPLIER_USER_ID => User::factory()->create()->id,
        ]);

        return $order->fresh();
    }

    /** @return list<int> */
    private function ids(string $path): array
    {
        Sanctum::actingAs($this->admin);

        return collect($this->getJson($path)->assertOk()->json('data'))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    #[Test]
    public function the_supplier_tax_tab_lists_moadian_invoices_not_payment_receipts(): void
    {
        $withMoadian = $this->makeOrder([Order::SUPPLIER_MOADIAN_REF => 'SUP-MOADIAN-1']);
        $withReceiptOnly = $this->makeOrder([Order::PAYMENT_RECEIPT_REF => 'receipts/whatever.jpg']);

        $ids = $this->ids('/api/admin/financial/supplier/documents?doc_type=tax');

        $this->assertContains($withMoadian->id, $ids, 'سفارشِ دارای فاکتور مودیانِ تأمین‌کننده باید بیاید.');
        $this->assertNotContains($withReceiptOnly->id, $ids, 'فیشِ تصویریِ پرداخت سندِ مالیاتی نیست.');
    }

    #[Test]
    public function the_elite_yar_documents_report_is_not_permanently_empty(): void
    {
        $submitted = $this->makeOrder([Order::ELITE_YAR_MOADIAN_REF => 'EY-MOADIAN-1']);
        $notSubmitted = $this->makeOrder();

        $ids = $this->ids('/api/admin/financial/expert/documents');

        $this->assertContains($submitted->id, $ids);
        $this->assertNotContains($notSubmitted->id, $ids);
    }

    #[Test]
    public function elite_yar_shares_find_the_real_commission_legs(): void
    {
        // ⚠️ ردیف‌ها را **با خودِ موتورِ کیف پول** می‌سازیم، نه با
        // `WalletTransaction::create()` دستی.
        //
        // نسخهٔ قبلیِ این تست ردیف‌ها را دستی می‌ساخت و به آن‌ها شکلی می‌داد که
        // موتور اصلاً تولید نمی‌کند: `type = hold_release` همراهِ `meta.code = WEY`.
        // `releaseHold()` تنها نویسندهٔ آن نوع است و `meta.code` **نمی‌نویسد**؛
        // مسیرِ الیت‌یار هم هرگز صدایش نمی‌زند. پس تست سبز بود و گزارشِ «پرداخت‌شده»
        // در پروداکشن برای همیشه تهی می‌ماند — همان کلاسِ باگی که قرار بود بسته شود.
        //
        // با فراخوانیِ موتور، هر تغییری در شکلِ ردیف‌ها خودبه‌خود به این تست می‌رسد.
        $eliteYar = User::factory()->create();
        Wallet::create([
            Wallet::USER_ID => $eliteYar->id,
            Wallet::BALANCE => 0,
            Wallet::BALANCE_HOLD => 0,
        ]);

        $supplier = User::factory()->create();
        Wallet::create([
            Wallet::USER_ID => $supplier->id,
            Wallet::BALANCE => 5_000,
            Wallet::BALANCE_HOLD => 0,
        ]);

        $order = $this->makeOrder();
        $engine = app(WalletTransactionEngine::class);
        $amount = 1_000_000.0;

        // ۱) باسکول: کمیسیون امانی پارک می‌شود (WeighbridgeVatRouter).
        $engine->creditEliteYAR($order, $amount, FinancialCodes::STATUS_HOLD, $eliteYar->id);

        // ۲) سند Q: هولد آزاد می‌شود — دقیقاً همان جفتی که
        //    `ReleaseEliteYARHoldAction` می‌زند (debit HOLD سپس credit AVAILABLE).
        $engine->debitEliteYAR($order, $amount, FinancialCodes::STATUS_HOLD, $eliteYar->id);
        $engine->creditEliteYAR($order, $amount, FinancialCodes::STATUS_AVAILABLE, $eliteYar->id);

        // ۳) یک لِگِ کیف پولِ دیگر که نباید در هیچ‌کدام از دو حالت بیاید.
        //    credit است نه debit: برداشت از `balance_hold` موجودیِ قبلی می‌خواهد،
        //    و اینجا فقط یک ردیفِ WS لازم داریم تا مطمئن شویم فیلترِ WEY آن را
        //    نمی‌گیرد. این همان شکلی است که هولدِ مالیاتِ تأمین‌کننده ساخته می‌شود.
        $engine->creditSupplier($order, 500.0, FinancialCodes::STATUS_HOLD, $supplier->id);

        $legs = WalletTransaction::query()
            ->where(WalletTransaction::ORDER_ID, $order->id)
            ->get();

        $held = $legs->first(fn ($t) => ($t->meta['code'] ?? null) === FinancialCodes::WALLET_ELITE_YAR
            && ($t->meta['op'] ?? null) === '+'
            && $t->status === FinancialCodes::STATUS_HOLD);
        $releaseDebit = $legs->first(fn ($t) => ($t->meta['code'] ?? null) === FinancialCodes::WALLET_ELITE_YAR
            && ($t->meta['op'] ?? null) === '-');
        $released = $legs->first(fn ($t) => ($t->meta['code'] ?? null) === FinancialCodes::WALLET_ELITE_YAR
            && ($t->meta['op'] ?? null) === '+'
            && $t->status === FinancialCodes::STATUS_AVAILABLE);
        $unrelated = $legs->first(fn ($t) => ($t->meta['code'] ?? null) === FinancialCodes::WALLET_SUPPLIER);

        $this->assertNotNull($held, 'موتور باید لِگِ امانیِ WEY را نوشته باشد.');
        $this->assertNotNull($releaseDebit, 'موتور باید لِگِ برداشتِ آزادسازی را نوشته باشد.');
        $this->assertNotNull($released, 'موتور باید لِگِ آزادشدهٔ WEY را نوشته باشد.');
        $this->assertNotNull($unrelated, 'لِگِ تأمین‌کننده باید ساخته شده باشد.');

        $allocation = $this->ids('/api/admin/financial/company/expert-shares?mode=allocation');
        $this->assertContains($held->id, $allocation, 'کمیسیونِ امانیِ الیت‌یار باید در حالتِ تخصیص بیاید.');
        // ⚠️ لِگِ برداشتِ سند Q هم `code=WEY` و `status=hold` دارد. بدونِ شرطِ
        // `op = '+'`، کمیسیونِ **آزادشده** دوباره به‌عنوان امانی شمرده می‌شد و چون
        // Resource مبلغ را بی‌علامت می‌دهد، پنل رقم را دوبرابر نشان می‌داد.
        $this->assertNotContains($releaseDebit->id, $allocation, 'لِگِ برداشتِ آزادسازی نباید دوباره امانی شمرده شود.');
        $this->assertNotContains($unrelated->id, $allocation, 'لِگِ کیف پولِ دیگری نباید بیاید.');

        $payment = $this->ids('/api/admin/financial/company/expert-shares?mode=payment');
        $this->assertContains($released->id, $payment, 'کمیسیونِ آزادشده باید در حالتِ پرداخت بیاید.');
        $this->assertNotContains($held->id, $payment, 'مبلغِ هنوز امانی پرداخت‌شده نیست.');
        $this->assertNotContains($unrelated->id, $payment, 'لِگِ کیف پولِ دیگری نباید بیاید.');
    }
}
