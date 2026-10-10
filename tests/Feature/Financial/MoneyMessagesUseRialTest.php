<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Models\User;
use App\Models\Wallet;
use App\Services\AnalyticsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * همه‌ی مبلغ‌های سیستم ریال‌اند؛ این سه پیام عدد را «تومان» می‌نامیدند — یعنی حدی ده
 * برابر کوچک‌تر از حدِ واقعی به کاربر/ادمین نشان می‌دادند.
 */
class MoneyMessagesUseRialTest extends TestCase
{
    use DatabaseTransactions;

    private function assertRial(string $text): void
    {
        $this->assertStringContainsString('ریال', $text);
        $this->assertStringNotContainsString('تومان', $text);
    }

    #[Test]
    public function the_withdrawal_minimum_is_stated_in_rials(): void
    {
        $user = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $user->id, Wallet::BALANCE => 5_000_000, Wallet::BALANCE_HOLD => 0]);
        Sanctum::actingAs($user);

        $message = $this->postJson('/api/profile/financial/wallet/withdraw', ['amount' => 1])
            ->assertStatus(422)
            ->json('errors.amount.0');

        $this->assertRial($message);
        $this->assertStringContainsString(number_format((int) config('financial.withdrawal_min')), $message);
    }

    #[Test]
    public function the_receipt_minimum_is_stated_in_rials(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $message = $this->postJson('/api/profile/financial/receipts', [
            'amount' => 1,
            'receipt_number' => 'R-1',
            'payment_date' => '1405/07/19',
            'bank_name' => 'ملت',
            'receipt_image' => UploadedFile::fake()->image('r.jpg'),
        ])->assertStatus(422)->json('errors.amount.0');

        $this->assertRial($message);
    }

    #[Test]
    public function the_revenue_chart_is_labelled_in_rials(): void
    {
        Cache::forget('analytics_revenue_7');

        $label = app(AnalyticsService::class)->getRevenueChartData(7)['datasets'][0]['label'];

        $this->assertRial($label);
    }
}
