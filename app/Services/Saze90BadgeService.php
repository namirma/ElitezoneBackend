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
 * FAIL-SAFE: any error (network, circuit open, bad shape) returns null — never throws to
 * the caller. Only a successful, non-empty result is cached (so an outage is retried, not
 * memoised). saze90 does NOT expose the badge *purchase amount*, so the eliteYAR lifetime
 * cap (which needs that) stays dormant — pending a separate endpoint from the supervisor.
 */
class Saze90BadgeService
{
    use HasCircuitBreaker;

    /** Cache sentinel for a confirmed "this mobile has no badge" (distinct from an outage). */
    private const NONE = '__none__';

    /**
     * @return string|null lowercased badge code (e.g. "gold"), or null when none/unavailable.
     */
    public function getCertificate(string $mobile): ?string
    {
        if (! config('services.saze90.enabled') || $mobile === '') {
            return null;
        }

        $cacheKey = "saze90_badge:{$mobile}";
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

                $message = $resp->json('message');

                return (is_string($message) && trim($message) !== '') ? strtolower(trim($message)) : self::NONE;
            });
        } catch (\Throwable $e) {
            // Outage / circuit open → return null WITHOUT caching, so the next call retries.
            Log::warning('Saze90 badge fetch failed', ['mobile' => $mobile, 'error' => $e->getMessage()]);

            return null;
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
