<?php

declare(strict_types=1);

namespace App\Actions\Order;

final class PayOrderResult
{
    public function __construct(
        public readonly string $scenario,   // 'completion' | 'direct_purchase' | 'reservation'
        public readonly array  $documents,
        public readonly string $messageKey,
    ) {}
}
