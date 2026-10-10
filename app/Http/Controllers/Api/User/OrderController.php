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
     * Order-list tab counters: one `total` per list, equal to that list's `meta.total`.
     */
    public function counts(): JsonResponse
    {
        $userId = (int) Auth::id();

        // همان دو scope و همان گروه‌هایی که شش فهرست می‌خوانند، تا عدد و فهرست از هم جدا نشوند.
        $mine = fn (string $group): int => Order::query()->buyerSideOf($userId)->inStatusGroup($group)->count();
        $others = fn (string $group): int => Order::query()->placedForOthersBy($userId)->inStatusGroup($group)->count();

        return success([
            'buy_myself' => ['current' => (int) $mine('current'), 'delivered' => (int) $mine('delivered'), 'cancelled' => (int) $mine('cancelled')],
            'buy_others' => ['current' => (int) $others('current'), 'delivered' => (int) $others('delivered'), 'cancelled' => (int) $others('cancelled')],
        ]);
    }

    /**
     * متد کمکی برای لیست سفارش‌های "دیگران" (Buy Others)
     */
    public function otherOrders(Request $request, string $type)
    {
        $userId = Auth::id();

        // scopeها داخلِ closure، تا زنجیره‌ی بیرونی Eloquentِ استاندارد بماند و Scramble
        // پاکتِ صفحه‌بندی (meta/links) را در api.json ببیند.
        $orders = Order::query()
            ->where(fn ($q) => $q->placedForOthersBy($userId)->inStatusGroup($type))
            ->with(Order::resourceRelations())
            ->latest()
            ->paginate($this->perPage(10));

        return success(OrderResource::collection($orders), 'global.received_successfully');
    }
}
