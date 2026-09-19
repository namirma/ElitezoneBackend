<?php

namespace App\Jobs\Auth;

use App\Actions\Auth\SyncIdentityToProfileAction;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * پر کردن پروفایل از استعلام هویتِ پودیوم، خارج از مسیر ورود.
 *
 * این کار تا پیش از این *همزمان* داخل `AuthController::verifyCode` اجرا می‌شد،
 * یعنی هر ورود با کد یک‌بارمصرف منتظر یک درخواست HTTP به پودیوم می‌ماند که
 * timeout پیش‌فرضش ۳۰ ثانیه است (`podium.defaults.timeout`). اکشن خودش
 * try/catch دارد پس ورود هیچ‌وقت *شکست* نمی‌خورد — ولی وقتی پودیوم کند یا
 * در دسترس نباشد، ورودِ همه‌ی کاربران تا ۳۰ ثانیه بلاک می‌شد.
 *
 * پر شدن پروفایل برای موفقیت ورود لازم نیست (همان try/catch اثباتش می‌کند)،
 * پس به صف منتقل شد.
 */
class SyncIdentityToProfileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** بیشتر از timeout خودِ PodiumClient تا یک تلاشِ کند ناتمام رها نشود. */
    public int $timeout = 60;

    public int $tries = 3;

    /** @var list<int> ثانیه تا تلاش بعدی */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $userId) {}

    public function handle(SyncIdentityToProfileAction $action): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        $action->execute($user);
    }

    public function failed(Throwable $e): void
    {
        // شکست اینجا روی ورود اثری ندارد — پروفایل صرفاً پیش‌پر نمی‌شود.
        Log::warning('SyncIdentityToProfileJob failed after retries', [
            'user_id' => $this->userId,
            'error' => $e->getMessage(),
        ]);
    }
}
