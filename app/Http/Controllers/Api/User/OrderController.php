<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;

use App\Http\Resources\Order\OrderResource;
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
            perPage: $this->perPage(10)
        );

        return success(OrderResource::collection($orders), __('global.received_successfully'));
    }

    /**
     * نمایش جزئیات یک سفارش خاص
     */
    public function show(Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        $order->load(array_merge(Order::resourceRelations(), [
            'items.supplier.profile',   // extra: نمایش اطلاعات تامین‌کننده در detail
        ]));

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
            perPage: $this->perPage(10)
        );

        return success(OrderResource::collection($orders));
    }

    /**
     * متد کمکی برای لیست سفارش‌های "دیگران" (Buy Others)
     */
    public function otherOrders(Request $request, string $type)
    {
        $userId = Auth::id();

        $orders = Order::query()
            ->where(Order::PLACER_USER_ID, $userId)
            ->whereNotNull(Order::BENEFICIARY_USER_ID)
            ->when($type === 'current',   fn ($q) => $q->current())
            ->when($type === 'delivered', fn ($q) => $q->delivered())
            ->when($type === 'cancelled', fn ($q) => $q->cancelled())
            ->with(Order::resourceRelations())
            ->latest()
            ->paginate($this->perPage(10));

        return success(OrderResource::collection($orders), 'global.received_successfully');
    }
}
