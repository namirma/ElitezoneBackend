<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * گاردِ مهاجرتِ `standardise_money_columns_to_integer_rials`.
 *
 * روی دیتابیسِ تست همه‌ی ستون‌های مبلغ از قبل `decimal(20,0)` هستند و مقدارِ
 * کسری اصلاً ذخیره نمی‌شود، پس گارد روی ستونی آزموده می‌شود که هنوز اعشار دارد
 * (`orders.owner_discount_rate`, `decimal(5,2)`). منطق همان است: ستون از ورودی
 * می‌آید، نه از فهرستِ ثابت.
 */
class MoneyMigrationFractionGuardTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function a_fractional_value_is_named_and_a_missing_column_is_skipped(): void
    {
        Queue::fake();

        $order = Order::create([
            Order::PLACER_USER_ID => User::factory()->create()->id,
            Order::ORDER_NUMBER => 'EZ-MIG-'.Str::random(10),
            Order::STATUS => Order::STATUS_SHIPPED,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);
        DB::table('orders')->where('id', $order->id)->update(['owner_discount_rate' => 10.5]);

        $migration = require database_path('migrations/2026_08_29_120000_standardise_money_columns_to_integer_rials.php');

        $found = (fn (array $columns) => $this->fractionalMoney($columns))->call($migration, [
            'orders' => ['owner_discount_rate' => 'decimal(5,2)'],
            'no_such_table' => ['amount' => 'decimal(20,2)'],
        ]);

        $this->assertSame(['orders.owner_discount_rate'], $found);
    }
}
