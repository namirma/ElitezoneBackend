<?php

namespace App\Jobs;

use App\Models\Product;
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
        protected ?string $sessionId
    ) {}

    public function handle(): void
    {
        ProductView::create([
            ProductView::PRODUCT_ID => $this->productId,
            ProductView::USER_ID    => $this->userId,
            ProductView::IP_ADDRESS => $this->ipAddress,
            ProductView::USER_AGENT => $this->userAgent,
            ProductView::SESSION_ID => $this->sessionId,
            ProductView::VIEWED_AT  => now(),
        ]);

        // افزایش بازدید
        Product::where('id', $this->productId)->increment('view_count');
    }
}
