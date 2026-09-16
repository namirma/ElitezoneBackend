<?php

namespace App\Jobs\Analytics;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductView;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class LogProductView implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected int $productId,
        protected ?int $userId,
        protected ?string $ipAddress,
        protected ?string $userAgent,
        protected ?string $sessionId,
        // بازدیدِ صفحه‌ی محصولِ سایت (`/product/{variantId}`) — «پربازدیدترین» در فهرستِ
        // واریانت‌ها روی همین شمارنده مرتب می‌شود؛ بدونِ آن هیچ داده‌ای نداشت.
        protected ?int $variantId = null,
    ) {}

    public function handle(): void
    {
        // ۱. فیلتر ربات — بازدید خزنده‌ها/اسکرپرها نباید آنالیتیکس و view_count را آلوده کند.
        if ($this->isBot($this->userAgent)) {
            return;
        }

        // ۲. dedup — همان session که همان محصول را در پنجره‌ی کوتاه دوباره می‌بیند
        // (رفرش/اسکرول)، تکراری شمرده نمی‌شود.
        if ($this->isDuplicate()) {
            return;
        }

        ProductView::create([
            ProductView::PRODUCT_ID => $this->productId,
            ProductView::PRODUCT_VARIANT_ID => $this->variantId,
            ProductView::USER_ID => $this->userId,
            // IP برای حریم خصوصی ناشناس می‌شود (اوکتت/هگزِ آخر صفر) — برای آمار جغرافیایی
            // کافی است ولی فرد قابل شناسایی نیست.
            ProductView::IP_ADDRESS => $this->anonymizeIp($this->ipAddress),
            ProductView::USER_AGENT => $this->userAgent ? mb_substr($this->userAgent, 0, 255) : null,
            ProductView::SESSION_ID => $this->sessionId,
            ProductView::VIEWED_AT => now(),
        ]);

        Product::where('id', $this->productId)->increment('view_count');

        if ($this->variantId) {
            ProductVariant::where('id', $this->variantId)->increment(ProductVariant::VIEW_COUNT);
        }
    }

    private function isBot(?string $agent): bool
    {
        if (empty($agent)) {
            return true; // بدون User-Agent معمولاً ربات/اسکریپت است
        }

        $agent = strtolower($agent);
        foreach ((array) config('intelligence.product_views.bot_user_agents', []) as $needle) {
            if (str_contains($agent, strtolower((string) $needle))) {
                return true;
            }
        }

        return false;
    }

    private function isDuplicate(): bool
    {
        // بازدیدِ واریانت یک‌بار در روز به‌ازای هر بازدیدکننده شمرده می‌شود (نه هر
        // ۳۰ ثانیه): رفت‌وبرگشتِ بینِ کارت و صفحه‌ی محصول نباید «پربازدیدترین» را باد کند.
        $window = $this->variantId
            ? (int) config('intelligence.product_views.variant_dedup_seconds', 86400)
            : (int) config('intelligence.product_views.dedup_seconds', 30);
        if ($window <= 0) {
            return false;
        }

        // کلیدِ dedup: کاربر لاگین‌شده با user_id، مهمان با session_id، در نبود هر دو با IP.
        $query = ProductView::where(ProductView::PRODUCT_ID, $this->productId)
            ->where(ProductView::PRODUCT_VARIANT_ID, $this->variantId)
            ->where(ProductView::VIEWED_AT, '>=', now()->subSeconds($window));

        if ($this->userId) {
            $query->where(ProductView::USER_ID, $this->userId);
        } elseif ($this->sessionId) {
            $query->where(ProductView::SESSION_ID, $this->sessionId);
        } else {
            $query->where(ProductView::IP_ADDRESS, $this->anonymizeIp($this->ipAddress));
        }

        return $query->exists();
    }

    private function anonymizeIp(?string $ip): ?string
    {
        if (empty($ip)) {
            return null;
        }

        if (str_contains($ip, ':')) {
            // IPv6 — فقط ۳ گروه اول نگه‌داشته می‌شود
            $parts = explode(':', $ip);
            $parts = array_slice($parts, 0, 3);

            return implode(':', $parts).'::';
        }

        // IPv4 — اوکتت آخر صفر می‌شود
        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            $parts[3] = '0';

            return implode('.', $parts);
        }

        return $ip;
    }
}
