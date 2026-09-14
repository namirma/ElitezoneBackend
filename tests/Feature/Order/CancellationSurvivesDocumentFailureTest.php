<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Order\CancelOrderAction;
use App\Events\Order\OrderCancelled;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\User;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * لغوِ انجام‌شده نباید با شکستِ ساختِ سندِ لغو «ناموفق» گزارش شود.
 *
 * اسنادِ لغو در `DB::afterCommit` ساخته می‌شوند — یعنی بعد از commit ولی هنوز
 * داخلِ `execute()`. وقتی ساختِ PDF خطا می‌داد (روی CI: فونتِ لایسنس‌دارِ غایب،
 * ۵۰۳)، سفارش واقعاً لغو و مسترد شده بود ولی:
 *   - ادمین «این سفارش در وضعیت قابل لغو نیست» (۴۲۲) می‌دید،
 *   - `OrderCancelled` dispatch نمی‌شد و مشتری هیچ اعلانی نمی‌گرفت،
 *   - و کرونِ انقضا روی همان سفارش متوقف می‌شد.
 */
class CancellationSurvivesDocumentFailureTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function a_failed_document_does_not_undo_or_hide_a_completed_cancellation(): void
    {
        Event::fake([OrderCancelled::class]);

        $this->mock(InvoiceGenerator::class, function ($mock) {
            $mock->shouldReceive('generateCancellationDocs')
                ->andThrow(new BusinessException('fonts missing', 503));
        });

        $order = Order::create([
            Order::PLACER_USER_ID => User::factory()->create()->id,
            Order::ORDER_NUMBER => 'CXL-'.Str::random(12),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::FINAL_AMOUNT => 100_000_000,
        ]);

        $cancelled = app(CancelOrderAction::class)->execute($order, 'آزمونِ شکستِ ساختِ سند پس از لغو', true, true);

        $this->assertSame(Order::STATUS_CANCELLED, $cancelled->fresh()->{Order::STATUS});
        Event::assertDispatched(OrderCancelled::class);
    }
}
