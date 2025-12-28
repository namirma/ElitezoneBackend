<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Repositories\Contracts\OrderRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
    public function __construct(
        protected OrderRepositoryInterface $orderRepository
    ) {}

    /**
     * لیست کلی سفارشات (همراه با فیلترهای استاندارد)
     */
    public function index(Request $request): JsonResponse
    {

        $orders = $this->orderRepository->getUserOrders(
            userId: Auth::id(),
            roleType: 'myself',
            statusGroup: 'current',
            perPage: $request->input('per_page', 10)
        );

        return success(OrderResource::collection($orders), __('global.received_successfully'));
    }

    /**
     * نمایش جزئیات یک سفارش خاص
     */
    public function show(Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        $order->load([
            'items.productVariant',
            'items.supplier.profile',
            'address',
            'placer.profile',
            'beneficiary.profile'
        ]);

        return success(new OrderResource($order), __('global.received_successfully'));
    }

    /**
     * متد کمکی برای لیست سفارش‌های "خودم" (Buy Myself)
     */
    public function myOrders(Request $request, string $statusGroup): JsonResponse
    {
        $orders = $this->orderRepository->getUserOrders(
            userId: Auth::id(),
            roleType: 'myself',
            statusGroup: $statusGroup,
            perPage: $request->input('per_page', 10)
        );
        return success(OrderResource::collection($orders));
    }

    /**
     * متد کمکی برای لیست سفارش‌های "دیگران" (Buy Others)
     */
    public function otherOrders(Request $request, string $type)
    {
        $userId = Auth::id();

        $query = Order::query()
            ->where('placer_user_id', $userId)
            ->whereNotNull('beneficiary_user_id');

        switch ($type) {
            case 'current':
                $query->whereIn('status', [
                    Order::STATUS_PENDING,
                    Order::STATUS_PROCESSING,
                    Order::STATUS_WEIGHBRIDGE_DONE,
                    Order::STATUS_PREPARING,
                    Order::STATUS_SHIPPED
                ]);
                break;

            case 'delivered':
                $query->where('status', Order::STATUS_DELIVERED);
                break;

            case 'cancelled':
                $query->whereIn('status', [Order::STATUS_CANCELLED, Order::STATUS_FAILED]);
                break;
        }

        $orders = $query->latest()->get();

        return success(OrderResource::collection($orders), 'global.received_successfully');
    }
}
