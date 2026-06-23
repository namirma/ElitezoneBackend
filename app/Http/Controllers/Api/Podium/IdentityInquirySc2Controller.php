<?php

namespace App\Http\Controllers\Api\Podium;

use App\Http\Controllers\Controller;
use App\Http\Requests\Podium\IdentityInquiryRequest;
use App\Services\PodiumServices\IdentityInquirySc2Service;
use Illuminate\Http\JsonResponse;

class IdentityInquirySc2Controller extends Controller
{
    public function __construct(private readonly IdentityInquirySc2Service $service) {}

    public function __invoke(IdentityInquiryRequest $request): JsonResponse
    {
        $normalized = $this->service->inquire(
            nationalCode: $request->validated('national_code'),
            birthDateJalali: $request->validated('birth_date'),
        );

        $data = $this->service->mapIdentityResponse($normalized);

        if ($normalized['has_error']) {
            return error(__('podium.identity_inquiry_error'), [], 422);
        }

        return success($data, __('podium.inquiry_success'));
    }
}
