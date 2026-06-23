<?php

namespace App\Http\Controllers\Api\Podium;

use App\Http\Controllers\Controller;
use App\Http\Requests\Podium\SettlementInquiryRequest;
use App\Services\PodiumServices\PodiumSettlementInquiryService;
use Illuminate\Http\JsonResponse;

class PodiumSettlementInquiryController extends Controller
{
    public function __construct(private readonly PodiumSettlementInquiryService $svc) {}

    public function index(SettlementInquiryRequest $req): JsonResponse
    {
        $res = $this->svc->search($req->validated());

        return response()->json(
            $res,
            200,
            [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
    }
}
