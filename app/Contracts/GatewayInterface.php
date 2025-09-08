<?php

namespace App\Contracts;

interface GatewayInterface
{
    public function getRequest(string $endpoint, array $data = []): array;
}
