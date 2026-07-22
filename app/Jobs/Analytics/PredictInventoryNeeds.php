<?php

namespace App\Jobs\Analytics;

use App\Services\InventoryIntelligenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PredictInventoryNeeds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [60, 300];

    public function handle(InventoryIntelligenceService $service): void
    {

        $service->runAnalysis();
    }

    public function failed(\Throwable $e): void
    {
        Log::error('PredictInventoryNeeds failed after retries', ['error' => $e->getMessage()]);
    }
}
