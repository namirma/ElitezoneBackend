<?php

namespace Tests\Feature\Security;

use App\Actions\Financial\RecordSystemMoadianInvoiceAction;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * `QueryException` یک `RuntimeException` است. پنج اکشن (`submit*MoadianInvoice` ×۳ و
 * `WalletController::uploadDepositReceipt/deleteReceipt`) `catch (\RuntimeException)`
 * داشتند و `$e->getMessage()` را خام با ۴۲۲ برمی‌گرداندند — SQL با bindingها، حتی روی
 * پروداکشن. حالا پیش از آن catch دوباره پرتاب می‌شود تا گاردِ Handler اعمال شود.
 */
class QueryExceptionIsNotLeakedTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    #[Test]
    public function a_query_exception_inside_a_moadian_action_returns_no_sql(): void
    {
        config(['app.debug' => false]);

        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        Sanctum::actingAs($this->grantAllAdminPermissions($admin));

        $order = Order::factory()->create();

        $this->mock(RecordSystemMoadianInvoiceAction::class)
            ->shouldReceive('execute')
            ->andThrow(new QueryException(
                'mysql',
                'update wallets set balance_hold = ? where user_id = ?',
                [987654321, 7],
                new PDOException('SQLSTATE[HY000]: General error'),
            ));

        $res = $this->postJson("/api/admin/orders/{$order->id}/system-moadian-invoice", ['moadian_ref' => 'REF-123']);

        $res->assertStatus(500)->assertJsonPath('message', __('global.errors.database_error'));
        foreach (['wallets', 'balance_hold', '987654321', 'SQLSTATE'] as $leak) {
            $this->assertStringNotContainsString($leak, $res->getContent());
        }
    }
}
