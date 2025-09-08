<?php

namespace App\Services\Gateways;

use App\Contracts\GatewayInterface;
use Illuminate\Support\Facades\Http;

class TesnaService implements GatewayInterface
{
    protected string $baseUrl;
    protected array $headers;

    public function __construct(array $config)
    {
        $this->baseUrl = $config['url'] ?? '';
        $this->headers = $config['headers'] ?? [];
    }

    public function getRequest(string $endpoint, array $data = []): array
    {
        $response = Http::withHeaders($this->headers)
            ->get($this->baseUrl . $endpoint, $data);

        return $response->json();
    }
}
