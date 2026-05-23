<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Financial\ProcessWeighbridgeResultAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\SubmitWeighbridgeRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use Exception;
use Illuminate\Http\JsonResponse;

class OrderWeighbridgeController extends Controller
{
    public function __construct(
        protected ProcessWeighbridgeResultAction $weighbridgeAction
    ) {}

    /**
     * ثبت وزن نهایی باسکول و انجام محاسبات مالی (کسری/سرک)
     */
    public function submitWeight(SubmitWeighbridgeRequest $request, Order $order): JsonResponse
    {
        $validatedData = $request->validated();
        $actualWeight = (float) $validatedData['weight_after_scale'];

        try {
            $result = $this->weighbridgeAction->execute($order, $actualWeight);

            $diffAmount = number_format((float) $result['financial_diff']);

            $message = match ($result['status']) {
                'surplus' => __('weighbridge.messages.surplus', ['amount' => $diffAmount]),
                'deficit' => __('weighbridge.messages.deficit', ['amount' => $diffAmount]),
                default => __('weighbridge.messages.equal'),
            };

            return success([
                'order' => new OrderResource($order->fresh()),
                'weight_details' => $result,
                'message' => $message,
            ]);

        } catch (Exception $e) {
            return error(__('weighbridge.errors.process_failed', ['message' => $e->getMessage()]), [], 400);
        }
    }
}
