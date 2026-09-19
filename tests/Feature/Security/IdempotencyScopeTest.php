<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * قرارداد میدل‌ور Idempotency — پیش از این هیچ تستی نداشت.
 *
 * باگی که این تست پین می‌کند: کلیدِ کش فقط (کاربر + Idempotency-Key) بود و متد/مسیر
 * و بدنه در آن نبود. پس اگر کلاینت یک UUID را برای دو درخواستِ مالیِ متفاوت به‌کار
 * می‌برد، پاسخِ اولی برای دومی replay می‌شد — سفارشِ دوم «پرداخت‌شده» جواب می‌گرفت
 * بدون آنکه هیچ پولی جابه‌جا شود.
 */
class IdempotencyScopeTest extends TestCase
{
    use DatabaseTransactions;

    /** شمارنده‌ی فراخوانی‌های واقعیِ هندلر — برای اثباتِ اینکه کش زده یا نزده. */
    private static int $hits = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        self::$hits = 0;

        // دو مسیرِ متفاوت + یک مسیر برای آزمونِ بدنه‌ی متفاوت، همگی پشت میدل‌ور.
        foreach (['alpha', 'beta'] as $name) {
            Route::middleware(['api', 'idempotency'])
                ->post("__idem/{$name}", function () use ($name) {
                    self::$hits++;

                    return success(['route' => $name, 'hits' => self::$hits]);
                });
        }
    }

    #[Test]
    public function the_same_key_on_the_same_endpoint_and_body_replays_the_cached_response(): void
    {
        $headers = ['Idempotency-Key' => 'key-123'];

        $first = $this->postJson('/__idem/alpha', ['amount' => 100], $headers);
        $second = $this->postJson('/__idem/alpha', ['amount' => 100], $headers);

        $first->assertOk();
        $second->assertOk();

        // هندلر فقط یک بار اجرا شده و دومی از کش آمده.
        $this->assertSame(1, self::$hits);
        $second->assertHeader('X-Idempotency-Hit', 'true');
        $this->assertSame(
            $first->json('data'),
            $second->json('data'),
            'پاسخ تکراری باید عیناً همان پاسخِ اول باشد.'
        );
    }

    #[Test]
    public function the_same_key_on_a_different_endpoint_does_not_replay(): void
    {
        $headers = ['Idempotency-Key' => 'shared-key'];

        $alpha = $this->postJson('/__idem/alpha', ['amount' => 100], $headers);
        $beta = $this->postJson('/__idem/beta', ['amount' => 100], $headers);

        $alpha->assertOk();
        $beta->assertOk();

        // هر دو هندلر واقعاً اجرا شده‌اند — این هسته‌ی باگ است.
        $this->assertSame(2, self::$hits);
        $this->assertSame('alpha', $alpha->json('data.route'));
        $this->assertSame('beta', $beta->json('data.route'), 'کلید مشترک نباید پاسخِ endpointِ دیگر را برگرداند.');
        $beta->assertHeaderMissing('X-Idempotency-Hit');
    }

    #[Test]
    public function reusing_a_key_on_the_same_endpoint_with_a_different_body_is_rejected(): void
    {
        $headers = ['Idempotency-Key' => 'key-456'];

        $this->postJson('/__idem/alpha', ['order_id' => 5], $headers)->assertOk();

        $reused = $this->postJson('/__idem/alpha', ['order_id' => 9], $headers);

        // نه پاسخِ کش‌شده (که یعنی پذیرفتنِ عملیاتی که هرگز اجرا نشد) و نه اجرای دوباره.
        $reused->assertStatus(422);
        $this->assertSame('IDEMPOTENCY_KEY_REUSED', $reused->json('errors.code'));
        $this->assertSame(1, self::$hits, 'درخواستِ ردشده نباید هندلر را اجرا کند.');
    }

    #[Test]
    public function requests_without_a_key_still_dedupe_identical_double_submits(): void
    {
        // مسیرِ fallback (اثرانگشت) — متد+مسیر+هشِ بدنه. باید دابل‌کلیک را بگیرد.
        $this->postJson('/__idem/alpha', ['amount' => 7])->assertOk();
        $this->postJson('/__idem/alpha', ['amount' => 7])->assertOk();

        $this->assertSame(1, self::$hits);

        // ولی بدنه‌ی متفاوت یک عملیاتِ مستقل است و باید اجرا شود.
        $this->postJson('/__idem/alpha', ['amount' => 8])->assertOk();
        $this->assertSame(2, self::$hits);
    }
}
