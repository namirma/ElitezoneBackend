<?php

namespace App\Services;

use App\Traits\Resilience\HasCircuitBreaker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class PodiumClient
{
    use HasCircuitBreaker;

    protected string $baseUrl;

    protected string $businessToken;

    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(Config::get('podium.base_url'), '/');
        $this->businessToken = (string) Config::get('podium.business_token');
        $this->timeout = (int) Config::get('podium.defaults.timeout', 30);

        $this->cbThreshold = 3;
        $this->cbTimeout = 120;
    }

    protected function http(): PendingRequest
    {
        return Http::asForm()
            ->timeout($this->timeout)
            ->withOptions([
                'proxy' => false,
            ])
            ->withHeaders([
                '_token_' => $this->businessToken,
                '_token_issuer_' => (string) Config::get('podium.defaults._token_issuer', 1),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]);
    }

    public function call(array $payload): array
    {
        return $this->guardTransport('podium_core', function () use ($payload) {

            $endpoint = $this->baseUrl.Config::get('podium.endpoints.do_service_call');
            $response = $this->http()->post($endpoint, $payload);

            if ($response->failed()) {
                Log::error('Podium HTTP error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                $response->throw();
            }

            return $response->json() ?? [];
        });
    }

    /**
     * لایه‌ی ترجمه‌ی خطاهای انتقال (TLS/DNS/timeout/۵xx سرویس) به یک خطای HTTP تمیز.
     *
     * بدون این، استثنای خام Guzzle تا هندلر سراسری بالا می‌رفت و کاربر پیامی مثل
     * «cURL error 35: TLS connect error … for https://api.pod.ir/…» می‌دید — هم
     * ناخوانا و هم ناقضِ قاعده‌ی «هرگز پیام خام سرویس بیرونی را برنگردان»
     * (جزئیات فنی فقط لاگ می‌شود).
     */
    protected function guardTransport(string $serviceKey, callable $callback): array
    {
        try {
            return $this->executeProtected($serviceKey, $callback);
        } catch (ConnectionException $e) {
            Log::error('Podium connection failed', [
                'service' => $serviceKey,
                'message' => $e->getMessage(),
            ]);
            throw new ServiceUnavailableHttpException(null, __('podium.service_unavailable'));
        } catch (RequestException $e) {
            Log::error('Podium request failed', [
                'service' => $serviceKey,
                'status' => $e->response?->status(),
                'message' => $e->getMessage(),
            ]);
            throw new ServiceUnavailableHttpException(null, __('podium.service_error'));
        }
    }

    protected function httpSc2(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->withHeaders([
                'Authorization' => 'Bearer '.$this->businessToken,
                'Content-Type' => 'application/json',
            ]);
    }

    public function callSc2(array $payload): array
    {
        return $this->guardTransport('podium_sc2', function () use ($payload) {

            $endpoint = $this->baseUrl.Config::get('podium.sc2.endpoints.do');
            $response = $this->httpSc2()->post($endpoint, $payload);

            if ($response->failed()) {
                Log::error('Podium SC2 HTTP error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                $response->throw();
            }

            return $response->json() ?? [];
        });
    }

    public static function normalize(array $res): array
    {
        $result = Arr::get($res, 'result');
        if (is_string($result)) {
            $trim = ltrim($result);
            if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
                $decoded = json_decode($result, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $result = $decoded;
                }
            }
        }

        return [
            'has_error' => (bool) Arr::get($res, 'hasError', true),
            'message_id' => Arr::get($res, 'messageId'),
            'message' => Arr::get($res, 'message'),
            'reference_number' => Arr::get($res, 'referenceNumber'),
            'ott' => Arr::get($res, 'ott'),
            'error_code' => Arr::get($res, 'errorCode'),
            'status_code' => Arr::get($res, 'statusCode'),
            'result' => $result,
            'raw' => $res,
        ];
    }
}
