<?php

namespace App\Jobs\Analytics;

use App\Services\InventoryIntelligenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PredictInventoryNeeds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(InventoryIntelligenceService $service): void
    {

        $service->runAnalysis();
    }
}
