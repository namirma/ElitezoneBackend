<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrderFilterRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
    public function history(Order $order): JsonResponse
    {

        $activities = $order->activities()
            ->with('causer')
            ->latest()
            ->get();

        $history = $activities->map(function ($activity) {
            return [
                'event' => $activity->description,
                'causer_name' => $activity->causer ? $activity->causer->profile->name : 'سیستم',
                'created_at' => verta($activity->created_at)->format('Y/m/d H:i'),
                'changes' => $activity->properties['attributes'] ?? [],
                'old' => $activity->properties['old'] ?? [],
            ];
        });

        return success($history, 'تاریخچه سفارش دریافت شد.');
    }

    public function index(OrderFilterRequest $request): JsonResponse
    {

        $orders = Order::query()
            ->with(['placer:id,mobile'])
            ->when($request->filled('order_number'), fn (Builder $q) => $q->where(Order::ORDER_NUMBER, 'like', "%{$request->order_number}%")
                ->orWhere(Order::ID, $request->order_number)
            )
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->date_from)
            )
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->date_to)
            )
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->status)
            )
            ->latest()
            ->paginate($request->get('per_page', 20));

        return success(OrderResource::collection($orders));
    }

    public function show(Order $order): JsonResponse
    {
        $order->load(['items', 'address', 'placer.profile']);

        return success(new OrderResource($order));
    }

    public function changeStatus(Request $request, Order $order): JsonResponse
    {
        $request->validate(['status' => 'required|string|in:pending,processing,shipped,delivered,cancelled']);

        $order->update(['status' => $request->input('status')]);

        return success(null, __('admin.orders.status_updated'));
    }
}
