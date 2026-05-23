<?php

namespace App\Http\Controllers\Api\Podium;

use App\Http\Controllers\Controller;

use App\Http\Requests\Podium\BatchSettlementRequest;
use App\Services\PodiumServices\PodiumBatchSettlementService;
use Illuminate\Http\JsonResponse;

class PodiumBatchSettlementController extends Controller
{
    public function __construct(private readonly PodiumBatchSettlementService $svc) {}

    public function store(BatchSettlementRequest $req): JsonResponse
    {
        $res = $this->svc->settle($req->validated());

        return response()->json(
            $res,
            200,
            [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
    }
}
