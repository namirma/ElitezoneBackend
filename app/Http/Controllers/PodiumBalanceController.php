<?php

namespace App\Http\Controllers;

use App\Http\Requests\Podium\GetBalanceRequest;
use App\Services\PodiumServices\PodiumBalanceService;
use Illuminate\Http\JsonResponse;

class PodiumBalanceController extends Controller
{
    public function __construct(private readonly PodiumBalanceService $svc) {}

    public function index(GetBalanceRequest $req): JsonResponse
    {
        $res = $this->svc->getBusinessBalances($req->validated('guildCode'));
        $res = array_filter($res, fn($v) => $v !== null);
        return response()->json(
            $res,
            200,
            [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
    }
}
