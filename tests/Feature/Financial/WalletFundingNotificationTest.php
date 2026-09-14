<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Events\Financial\WalletFunded;
use App\Jobs\Notification\SendSmsJob;
use App\Listeners\Financial\SendWalletFundingNotification;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * اعلانِ «کیف پول شارژ شد» برای هر منبعِ شارژ.
 *
 * ‏`WalletFunded` از دو جا می‌آید: کال‌بکِ درگاه (`meta.ref_id` دارد) و تأییدِ
 * واریزِ پادیوم توسط ادمین (`meta.doc_number` دارد، `ref_id` ندارد). listener
 * بی‌قید `meta['ref_id']` می‌خواند، پس روی مسیرِ دوم خطا می‌داد و چون همه‌چیز
 * داخلِ `try` بود، کاربر **نه پیامک گرفت نه اعلانِ زنگوله** — فقط یک خطِ لاگ.
 */
class WalletFundingNotificationTest extends TestCase
{
    use DatabaseTransactions;

    /** @param  array<string, mixed>  $meta */
    private function fund(User $user, array $meta): void
    {
        $wallet = Wallet::create([Wallet::USER_ID => $user->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $transaction = $wallet->transactions()->create([
            WalletTransaction::AMOUNT => 5_000_000,
            WalletTransaction::TYPE => WalletTransaction::TYPE_DEPOSIT,
            WalletTransaction::STATUS => WalletTransaction::STATUS_CONFIRMED,
            WalletTransaction::DESCRIPTION => 'شارژ آزمایشی',
            WalletTransaction::META => $meta,
        ]);

        (new SendWalletFundingNotification)->handle(new WalletFunded($user, $transaction));
    }

    #[Test]
    public function an_admin_confirmed_podium_deposit_still_notifies_with_its_document_number(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->fund($user, ['source' => 'podium_admin_credit', 'doc_number' => '884512']);

        $notification = UserNotification::where(UserNotification::USER_ID, $user->id)->first();

        $this->assertNotNull($notification, 'شارژِ پادیوم هیچ اعلانی نساخت — همان باگِ ref_id.');
        $this->assertStringContainsString('884512', $notification->{UserNotification::BODY});
        Queue::assertPushed(SendSmsJob::class);
    }

    #[Test]
    public function a_gateway_payment_keeps_its_reference_id(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->fund($user, ['ref_id' => 'REF-7788']);

        $this->assertStringContainsString(
            'REF-7788',
            UserNotification::where(UserNotification::USER_ID, $user->id)->value(UserNotification::BODY)
        );
    }

    #[Test]
    public function no_tracking_code_means_no_empty_tracking_line(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->fund($user, []);

        $body = (string) UserNotification::where(UserNotification::USER_ID, $user->id)->value(UserNotification::BODY);

        $this->assertNotSame('', $body, 'نبودِ کدِ پیگیری نباید اعلان را از بین ببرد.');
        // قاعده‌ی صداقت: برچسبی با مقدارِ خالی نشان داده نمی‌شود.
        $this->assertStringNotContainsString('کد پیگیری', $body);
    }
}
