<?php

namespace App\Services;

use App\Traits\Resilience\HasCircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads a consultant's collaboration badge from the legacy «سازه ۹۰» (saze90.com)
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
 * memoised). saze90 does NOT expose the badge *purchase amount*, so the consultant lifetime
 * cap (which needs that) stays dormant — pending a separate endpoint from the supervisor.
 */
class Saze90BadgeService
{
    use HasCircuitBreaker;

    /**
     * @return string|null lowercased badge code (e.g. "gold"), or null when none/unavailable.
     */
    public function getCertificate(string $mobile): ?string
    {
        if (! config('services.saze90.enabled') || $mobile === '') {
            return null;
        }

        $cacheKey = "saze90_badge:{$mobile}";
        if (($cached = Cache::get($cacheKey)) !== null) {
            return $cached ?: null;
        }

        try {
            $code = $this->executeProtected('saze90_badge', function () use ($mobile) {
                $base = rtrim((string) config('services.saze90.base_url'), '/');

                $resp = Http::timeout((int) config('services.saze90.timeout', 5))
                    ->acceptJson()
                    ->get($base.'/get-certificate', ['mobile' => $mobile]);

                if (! $resp->successful()) {
                    return null;
                }

                $message = $resp->json('message');
                if (! is_string($message) || trim($message) === '') {
                    return null;
                }

                return strtolower(trim($message));
            });
        } catch (\Throwable $e) {
            Log::warning('Saze90 badge fetch failed', ['mobile' => $mobile, 'error' => $e->getMessage()]);

            return null;
        }

        // Cache successful lookups only; never memoise a null (outage/no-badge) result.
        if ($code) {
            Cache::put($cacheKey, $code, (int) config('services.saze90.cache_ttl', 1800));
        }

        return $code;
    }
}
