<?php

namespace App\Http\Controllers\Api\Podium;

use App\Http\Controllers\Controller;
use App\Http\Requests\Podium\MobileNidMatchRequest;
use App\Services\PodiumServices\MobileNidMatchService;
use Illuminate\Http\JsonResponse;

class MobileNidMatchController extends Controller
{
    public function __construct(private readonly MobileNidMatchService $service) {}

    public function store(MobileNidMatchRequest $request): JsonResponse
    {
        $normalized = $this->service->match(
            nationalCode:  $request->validated('national_code'),
            mobileNumber:  $request->validated('mobile_number'),
        );

        if ($normalized['has_error']) {
            return error($normalized['message'] ?? 'خطا در تطابق هویتی', [], 422);
        }

        $mapped = $this->service->mapMatchResponse(
            $normalized,
            $request->validated('national_code'),
            $request->validated('mobile_number'),
        );

        return success($mapped['payload'], $mapped['message']);
    }
}
