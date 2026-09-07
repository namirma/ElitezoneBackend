<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
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
        $eliteYar = User::factory()->create();
        $wallet = Wallet::create([
            Wallet::USER_ID => $eliteYar->id,
            Wallet::BALANCE => 0,
            Wallet::BALANCE_HOLD => 0,
        ]);
        $order = $this->makeOrder();

        // همان شکلی که `WeighbridgeVatRouter::creditEliteYAR()` می‌سازد:
        // نوع `deposit`، وضعیت `hold`، و `meta.code = WEY`.
        $held = WalletTransaction::create([
            WalletTransaction::WALLET_ID => $wallet->id,
            WalletTransaction::ORDER_ID => $order->id,
            WalletTransaction::TYPE => WalletTransaction::TYPE_DEPOSIT,
            WalletTransaction::STATUS => FinancialCodes::STATUS_HOLD,
            WalletTransaction::AMOUNT => 1_000_000,
            WalletTransaction::META => ['code' => FinancialCodes::WALLET_ELITE_YAR, 'op' => '+'],
        ]);

        // و آزادسازیِ سند Q.
        $released = WalletTransaction::create([
            WalletTransaction::WALLET_ID => $wallet->id,
            WalletTransaction::ORDER_ID => $order->id,
            WalletTransaction::TYPE => WalletTransaction::TYPE_HOLD_RELEASE,
            WalletTransaction::STATUS => FinancialCodes::STATUS_AVAILABLE,
            WalletTransaction::AMOUNT => 1_000_000,
            WalletTransaction::META => ['code' => FinancialCodes::WALLET_ELITE_YAR, 'op' => '+'],
        ]);

        // یک لِگِ کیف پولِ دیگر که نباید در هیچ‌کدام از دو حالت بیاید.
        $unrelated = WalletTransaction::create([
            WalletTransaction::WALLET_ID => $wallet->id,
            WalletTransaction::ORDER_ID => $order->id,
            WalletTransaction::TYPE => WalletTransaction::TYPE_DEPOSIT,
            WalletTransaction::STATUS => FinancialCodes::STATUS_HOLD,
            WalletTransaction::AMOUNT => 500,
            WalletTransaction::META => ['code' => FinancialCodes::WALLET_SUPPLIER, 'op' => '+'],
        ]);

        $allocation = $this->ids('/api/admin/financial/company/expert-shares?mode=allocation');
        $this->assertContains($held->id, $allocation, 'کمیسیونِ امانیِ الیت‌یار باید در حالتِ تخصیص بیاید.');
        $this->assertNotContains($unrelated->id, $allocation, 'لِگِ کیف پولِ دیگری نباید بیاید.');

        $payment = $this->ids('/api/admin/financial/company/expert-shares?mode=payment');
        $this->assertContains($released->id, $payment, 'آزادسازیِ سند Q باید در حالتِ پرداخت بیاید.');
        $this->assertNotContains($held->id, $payment, 'مبلغِ هنوز امانی پرداخت‌شده نیست.');
    }
}
