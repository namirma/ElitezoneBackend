<?php

namespace App\Services;

use App\Traits\Resilience\HasCircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads a eliteYAR's collaboration badge from the legacy «سازه ۹۰» (saze90.com)
 * site. This call used to live on the frontend (`axios.get('https://www.saze90.com/
 * get-certificate?mobile=…')`) which broke under CORS; it now runs server-side.
 *
 * Contract (observed from the original frontend implementation):
 *   GET {base}/get-certificate?mobile=09xxxxxxxxx   (no auth)
 *   → 200 { "message": "gold"|"silver"|"bronze"|<saze90-user code>, "status": ... }
 *   "message" holds the badge code; a 401-ish status / empty message means "no badge".
 *
 * FAIL-SAFE, in two distinct shapes — the caller must be able to tell them apart:
 *   • a confirmed "no badge" returns null (cached briefly);
 *   • an outage (network, circuit open, bad shape) or a disabled integration THROWS
 *     {@see Saze90Unavailable}. It is never memoised, so the next call retries.
 * Before 1405/06/30 both were null, and `SyncUserBadgeAction` wrote "not a member" on
 * every outage — harmless while docs were the main path, fatal once saze90 membership is
 * the ONLY verification path.
 * saze90 does NOT expose the badge *purchase amount*, so the eliteYAR lifetime
 * cap (which needs that) stays dormant — pending a separate endpoint from the supervisor.
 */
class Saze90BadgeService
{
    use HasCircuitBreaker;

    /** Cache sentinel for a confirmed "this mobile has no badge" (distinct from an outage). */
    private const NONE = '__none__';

    /**
     * @return string|null lowercased badge code (e.g. "gold"), or null when confirmed none.
     *
     * @throws Saze90Unavailable when the answer is unknown (outage / disabled).
     */
    public function getCertificate(string $mobile): ?string
    {
        if ($mobile === '') {
            return null;
        }

        if (! config('services.saze90.enabled')) {
            throw new Saze90Unavailable('saze90 integration disabled');
        }

        // «v2»: خواننده‌ی پیش از ۱۴۰۵/۰۶/۳۰ متنِ «no user found.» را به‌عنوان کد کش کرده
        // بود (TTL ۳۰ دقیقه)؛ کلیدِ تازه یعنی آن ورودی‌ها هرگز دوباره خوانده نمی‌شوند.
        $cacheKey = "saze90_badge:v2:{$mobile}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            // '__none__' is the sentinel for a confirmed "no badge" (vs an outage we don't cache).
            return $cached === self::NONE ? null : $cached;
        }

        try {
            // Closure returns the lowercased code, or self::NONE for a confirmed no-badge.
            // A real HTTP/connection failure THROWS, so an outage is never memoised as "no badge".
            $code = $this->executeProtected('saze90_badge', function () use ($mobile) {
                $base = rtrim((string) config('services.saze90.base_url'), '/');

                $resp = Http::timeout((int) config('services.saze90.timeout', 5))
                    ->acceptJson()
                    ->get($base.'/get-certificate', ['mobile' => $mobile]);

                if (! $resp->successful()) {
                    throw new \RuntimeException('saze90 HTTP '.$resp->status());
                }

                // The payload carries its OWN status: an unknown mobile comes back as
                // HTTP 200 + {"message":"No user found.","status":401} (observed live,
                // 1405/06/30). Before this check that string was read as a badge code,
                // so every mobile saze90 had never heard of became a "member" — fatal
                // once membership is the only verification path.
                $payloadStatus = (int) $resp->json('status', 200);
                if ($payloadStatus >= 400) {
                    return self::NONE;
                }

                $message = $resp->json('message');

                return (is_string($message) && trim($message) !== '') ? strtolower(trim($message)) : self::NONE;
            });
        } catch (\Throwable $e) {
            // Outage / circuit open → nothing is cached, so the next call retries — and the
            // caller learns it is an outage, not a "no".
            Log::warning('Saze90 badge fetch failed', ['mobile' => $mobile, 'error' => $e->getMessage()]);

            throw new Saze90Unavailable($e->getMessage(), 0, $e);
        }

        if ($code === self::NONE) {
            // Cache a confirmed "no badge" for a SHORT window so non-members don't hit saze90 on
            // every profile load, while a newly-upgraded member is still picked up quickly.
            Cache::put($cacheKey, self::NONE, (int) config('services.saze90.cache_ttl_none', 300));

            return null;
        }

        Cache::put($cacheKey, $code, (int) config('services.saze90.cache_ttl', 1800));

        return $code;
    }
}
