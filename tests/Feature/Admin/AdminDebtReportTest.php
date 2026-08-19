<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AbandonedCart;
use App\Models\DebtEntry;
use App\Models\User;
use App\Services\Financial\DebtEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * فاز ۱۳ — گزارش‌های بک‌آفیس، بند ۷.۶ سند.
 */
class AdminDebtReportTest extends TestCase
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

    #[Test]
    public function the_debt_report_lists_debtors_with_their_headroom(): void
    {
        $debtor = User::factory()->create();
        $debtor->forceFill([User::CREDIT_LIMIT => 5_000_000])->save();
        app(DebtEngine::class)->incur($debtor->id, 2_000_000, DebtEntry::TYPE_OVERWEIGHT);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/admin/debts')->assertOk();

        $row = collect($response->json('data.debtors'))
            ->firstWhere('user_id', $debtor->id);

        $this->assertNotNull($row, 'بدهکار باید در گزارش باشد');
        $this->assertSame('2000000.00', $row['debt_balance']);
        $this->assertSame('3000000', $row['headroom'],
            'فاصله تا سقف همان چیزی است که تصمیمِ توقفِ سفارش به آن تکیه می‌کند');
    }

    #[Test]
    public function a_user_with_no_debt_is_absent_from_the_report(): void
    {
        $clean = User::factory()->create();

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/admin/debts')->assertOk();

        $this->assertNull(
            collect($response->json('data.debtors'))->firstWhere('user_id', $clean->id)
        );
    }

    #[Test]
    public function the_debt_ledger_of_one_user_is_readable(): void
    {
        // بدون دفتر، مانده یک عددِ بی‌توضیح است و بک‌آفیس نمی‌تواند بگوید
        // از کجا آمده.
        $debtor = User::factory()->create();
        $engine = app(DebtEngine::class);
        $engine->incur($debtor->id, 1_000_000, DebtEntry::TYPE_OVERWEIGHT);
        $engine->repay($debtor->id, 400_000);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson("/api/admin/users/{$debtor->id}/debt-entries")->assertOk();

        $this->assertSame('600000.00', $response->json('data.balance'));
        $this->assertCount(2, $response->json('data.entries'));
    }

    #[Test]
    public function the_abandoned_carts_report_returns_the_snapshot(): void
    {
        $user = User::factory()->create();
        $supplier = User::factory()->create();

        AbandonedCart::create([
            AbandonedCart::USER_ID => $user->id,
            AbandonedCart::SUPPLIER_USER_ID => $supplier->id,
            AbandonedCart::ITEMS => [['product_name' => 'میلگرد ۱۴', 'quantity' => 2]],
            AbandonedCart::TOTAL_AMOUNT => 7_500_000,
            AbandonedCart::CART_CREATED_AT => now()->subHours(3),
            AbandonedCart::EXPIRED_AT => now()->subHour(),
        ]);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/admin/abandoned-carts')->assertOk();

        $row = collect($response->json('data.data'))
            ->firstWhere('user.id', $user->id);

        $this->assertNotNull($row);
        $this->assertSame('7500000.00', $row['total_amount']);
        $this->assertSame('میلگرد ۱۴', $row['items'][0]['product_name'],
            'اسنپ‌شات تنها روایتِ درستِ آن سبد است — محصول ممکن است بعداً حذف شود');
    }

    #[Test]
    public function both_reports_are_gated_by_an_admin_permission(): void
    {
        $plain = User::factory()->create();

        Sanctum::actingAs($plain);
        $this->getJson('/api/admin/debts')->assertForbidden();
        $this->getJson('/api/admin/abandoned-carts')->assertForbidden();
    }
}
