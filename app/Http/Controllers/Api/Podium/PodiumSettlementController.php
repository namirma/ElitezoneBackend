<?php

namespace App\Http\Controllers\Api\Podium;

use App\Http\Controllers\Controller;

use App\Http\Requests\Podium\SingleSettlementRequest;
use App\Services\PodiumServices\PodiumSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class PodiumSettlementController extends Controller
{
    public function __construct(private readonly PodiumSettlementService $podium) {}

    public function single(SingleSettlementRequest $req): JsonResponse
    {
        $data = $req->validated();

        $data['wallet'] = $data['wallet'] ?? config('podium.defaults.wallet');
        $data['currencyCode'] = $data['currencyCode'] ?? config('podium.defaults.currency');

        $data['paymentId'] = $data['paymentId'] ?? ('pid-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)));
        $data['uniqueId'] = $data['uniqueId'] ?? ('wd-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6)));

        $res = $this->podium->singleSettlement($data);

        return response()->json(
            $res,
            200,
            [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
    }
}
