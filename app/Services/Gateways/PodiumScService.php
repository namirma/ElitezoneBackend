<?php

namespace App\Services\Gateways;

use App\Contracts\GatewayInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class PodiumScService implements GatewayInterface
{
    protected string $baseUrl;
    protected array $headers;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim((string) Arr::get($config, 'url', ''), '/');
        $this->headers = (array) Arr::get($config, 'headers', []);
    }
    public function getRequest(string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;

        $resp = Http::withHeaders($this->headers)
            ->acceptJson()
            ->asJson()
            ->post($url, $data);

        if ($resp->failed()) {
            return [
                'hasError'        => true,
                'messageId'       => null,
                'referenceNumber' => null,
                'errorCode'       => (string) $resp->status(),
                'message'         => $this->extractErrorMessage($resp),
                'result'          => null,
                'httpStatusCode'  => $resp->status(),
            ];
        }

        return $resp->json() ?? [];
    }

    protected function extractErrorMessage($response): string
    {
        $json = $response->json();
        if (is_array($json)) {
            return (string) (Arr::get($json, 'message') ?? Arr::get($json, 'error') ?? 'Bad Request');
        }
        return 'Bad Request';
    }
}
