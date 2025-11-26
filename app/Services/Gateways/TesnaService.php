<?php

namespace App\Services\Gateways;

use App\Contracts\GatewayInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log; 

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
        try {
            $response = Http::withHeaders($this->headers)
                ->timeout(10)
                ->get($this->baseUrl . $endpoint, $data);


            if ($response->failed()) {
                Log::error('Tesna API Error', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return [];
            }

            $data = $response->json();

            return $data ?? [];

        } catch (\Exception $e) {
            Log::error('Tesna Connection Failed: ' . $e->getMessage());
            return [];
        }
    }
}
