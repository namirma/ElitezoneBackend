<?php

namespace Tests\Feature\Audit;

use App\Actions\Order\FinalizeInvoiceAction;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Regression tests for the decision-batch fixes (D1-B remove phantom fee hold, D3 admin perms).
 */
#[Group('audit-fixes')]
class ReAuditFix3Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * D1-B — FinalizeInvoiceAction must ONLY record the invoice ref; it must NOT
     * create a fee hold or touch balance_hold (that commission is handled by the
     * WEY → Moadian flow; the old hold double-counted it and minted a phantom hold).
     */
    public function test_finalize_invoice_records_ref_without_holding_fee(): void
    {
        $eliteYar = User::factory()->create();
        $wallet = Wallet::factory()->create([Wallet::USER_ID => $eliteYar->id]); // balance_hold = 0

        $order = Order::factory()->create([
            Order::ELITE_YAR_USER_ID => $eliteYar->id,
            Order::ENGINEER_FEE_AMOUNT => 500000,
            Order::STATUS => Order::STATUS_PROCESSING,
        ]);

        app(FinalizeInvoiceAction::class)->execute($order, 'INV-REF-D1B');

        $this->assertSame('INV-REF-D1B', $order->fresh()->{Order::FINAL_INVOICE_REF});
        // No phantom hold minted:
        $this->assertEqualsWithDelta(0, (float) $wallet->fresh()->balance_hold, 0.01);
        $this->assertSame(0, WalletTransaction::where('order_id', $order->id)
            ->where('type', WalletTransaction::TYPE_FEE_HOLD)->count());
    }

    /** D1-B — a second call is idempotent (no error, ref unchanged). */
    public function test_finalize_invoice_is_idempotent(): void
    {
        $order = Order::factory()->create([Order::STATUS => Order::STATUS_PROCESSING]);

        $action = app(FinalizeInvoiceAction::class);
        $action->execute($order, 'REF-1');
        $action->execute($order->fresh(), 'REF-2'); // ignored — already finalized

        $this->assertSame('REF-1', $order->fresh()->{Order::FINAL_INVOICE_REF});
    }

    /**
     * D3 — sensitive admin route groups are gated by a granular permission,
     * including the Podium financial proxy (bank settlements).
     */
    public function test_admin_route_groups_are_permission_gated(): void
    {
        $src = file_get_contents(base_path('routes/api/admin.php'));

        // catalog
        $this->assertMatchesRegularExpression("/prefix\('products'\).*admin\.permission:manage-catalog/", $src);
        $this->assertMatchesRegularExpression("/prefix\('coupons'\).*admin\.permission:manage-catalog/", $src);
        // users
        $this->assertMatchesRegularExpression("/prefix\('suppliers'\).*admin\.permission:manage-users/", $src);
        // financial proxy (bank settlements) must be gated
        $this->assertStringContainsString('admin.permission:manage-financial', $src);
        $this->assertMatchesRegularExpression("/v1\/podium.*\n.*admin\.permission:manage-financial/", $src);
    }
}
