<?php

namespace App\Repositories\Eloquent;

use App\Models\Order;
use App\Repositories\Contracts\OrderRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class OrderRepository implements OrderRepositoryInterface
{
    /**
     * پیاده‌سازی متد جدید create برای PlaceOrderAction
     */
    public function create(array $data): Order
    {
        return Order::create($data);
    }

    /**
     * پیاده‌سازی متد قدیمی findById
     */
    public function findById(int $id): ?Order
    {
        return Order::with(['items', 'address', 'placer', 'beneficiary'])->find($id);
    }

    /**
     * پیاده‌سازی متد قدیمی getUserOrders (اصلاح شده)
     */
    public function getUserOrders(int $userId, string $roleType, string $statusGroup, int $perPage = 10): LengthAwarePaginator
    {
        return Order::query()
            // Single source of truth for eager-loads — same set OrderResource renders.
            // (Previously omitted `eliteYAR`, causing an N+1 when the resource read it.)
            ->with(Order::resourceRelations())
            ->where(function (Builder $query) use ($userId, $roleType) {
                if ($roleType === 'beneficiary') {
                    $query->where(Order::BENEFICIARY_USER_ID, $userId);

                    return;
                }

                // «سفارش‌های من» = هر سفارشی که در سمتِ خریدارش طرف هستم.
                //
                // پیش از این یعنی `placer = me AND (beneficiary IS NULL OR = me)`،
                // که در خرید-برای-دیگری **هیچ‌کدام از دو طرف** را برنمی‌گرداند:
                // الیت‌یار با شرطِ ذی‌نفع بیرون می‌افتاد و کارفرما با شرطِ ثبت‌کننده.
                // پس سفارشی که الیت‌یار برای کارفرما بسته در لیستِ هیچ‌کس نبود، و
                // چون تأییدِ تحویل payer-only است، تنها راهِ باقی‌مانده برای
                // پرداخت‌کننده لینکِ امضاشده‌ی پیامکی بود.
                //
                // همان مجموعه‌ای است که `OrderPolicy::view` سمتِ خریدار مجاز می‌داند.
                $query->where(Order::PLACER_USER_ID, $userId)
                    ->orWhere(Order::BENEFICIARY_USER_ID, $userId)
                    ->orWhere(Order::PAYER_USER_ID, $userId);
            })
            // فیلتر بر اساس گروه‌های وضعیت — از OrderBuilder scopes استفاده می‌شود
            ->when($statusGroup === 'current', fn ($q) => $q->current())
            ->when($statusGroup === 'delivered', fn ($q) => $q->delivered())
            ->when($statusGroup === 'cancelled', fn ($q) => $q->cancelled())
            ->latest()
            ->paginate($perPage);
    }

    public function update(Order $order, array $data): bool
    {
        return $order->update($data);
    }
}
