<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrderFilterRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

class AdminOrderController extends Controller
{
    public function index(OrderFilterRequest $request): JsonResponse
    {
        $orders = Order::query()
            ->with(['user:id,name,mobile,avatar']) // Eager Loading برای پرفورمنس
            ->when($request->filled('order_number'), fn(Builder $q) =>
                $q->where('tracking_code', 'like', "%{$request->order_number}%")
                  ->orWhere('id', $request->order_number)
            )
            ->when($request->filled('date_from'), fn(Builder $q) =>
                $q->whereDate('created_at', '>=', $request->date_from)
            )
            ->when($request->filled('date_to'), fn(Builder $q) =>
                $q->whereDate('created_at', '<=', $request->date_to)
            )
            ->when($request->filled('status'), fn(Builder $q) =>
                $q->where('status', $request->status)
            )
            ->latest()
            ->paginate($request->get('per_page', 20));

        return success(OrderResource::collection($orders));
    }

    public function show(Order $order): JsonResponse
    {
        $order->load(['items.product', 'transactions', 'address', 'user']);

        return success(new OrderResource($order));
    }

    public function changeStatus(Request $request, Order $order): JsonResponse
    {
        $request->validate(['status' => 'required|string|in:pending,processing,shipped,delivered,canceled']);

        $order->update(['status' => $request->input('status')]);


        return success(null, __('admin.orders.status_updated'));
    }
}
