<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\DebtEntry;
use App\Models\Order;
use App\Models\User;
use App\Services\Financial\DebtEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * بدهی خودِ کاربر — `GET /api/profile/debts` (§۷.۴).
 *
 * ⚠️ **چرا لازم است:** بدهی سه چیز را برای کاربر مسدود می‌کند — ثبت سفارش
 * (`PlaceOrderAction::assertNotInDebt`)، درخواست برداشت
 * (`RequestWithdrawalAction`)، و در اضافه‌بار، حملِ بار. پیش از این اندپوینت،
 * کاربر فقط پیامِ «به دلیل بدهی معوق ...» را می‌دید و **هیچ راهی نداشت
 * بفهمد چقدر، بابت چه، و از کِی**. تنها گزارشِ بدهی مالِ بک‌آفیس بود.
 *
 * فقط بدهیِ خودِ کاربرِ احرازشده — هیچ پارامترِ `user_id` نمی‌گیرد، تا این
 * اندپوینت به یک نشتِ بدهیِ دیگران تبدیل نشود.
 */
class UserDebtController extends Controller
{
    public function index(Request $request, DebtEngine $debtEngine): JsonResponse
    {
        $userId = (int) Auth::id();

        /** @var User|null $user */
        $user = User::whereKey($userId)->first();

        $entries = DebtEntry::query()
            ->where(DebtEntry::USER_ID, $userId)
            ->orderByDesc(DebtEntry::ID)
            ->paginate(min(100, max(10, $request->integer('per_page', 20))));

        // شماره‌ی سفارش را یک‌جا برمی‌داریم تا N+1 نشود؛ ردیفِ بدون سفارش
        // (تعدیل دستی) هم مجاز است، پس `?->` لازم است.
        $orderNumbers = Order::whereIn('id', $entries->getCollection()
            ->pluck(DebtEntry::ORDER_ID)->filter()->unique()->all())
            ->pluck(Order::ORDER_NUMBER, 'id');

        $rows = $entries->getCollection()->map(fn (DebtEntry $entry) => [
            'id' => $entry->id,
            'type' => $entry->{DebtEntry::TYPE},
            // منفی یعنی بازپرداخت — علامت عمداً حفظ می‌شود تا کاربر بتواند
            // دفتر را جمع بزند و به همان مانده برسد.
            'amount' => (string) $entry->{DebtEntry::AMOUNT},
            'description' => $entry->{DebtEntry::DESCRIPTION},
            'order_number' => $orderNumbers[$entry->{DebtEntry::ORDER_ID}] ?? null,
            'created_at' => $entry->{DebtEntry::CREATED_AT}?->toIso8601String(),
        ])->values()->all();

        $balance = $debtEngine->balanceFor($userId);
        // ‏`?->` سمت چپِ `??` روی شاخه‌ی L13 خطای larastan می‌دهد (آنجا نتیجه‌ی
        // `first()` غیرnullable تایپ می‌شود). شرطِ صریح روی هر دو شاخه یکسان
        // کار می‌کند و فایل بین دو برنچ واگرا نمی‌شود.
        $limit = $user === null ? 0.0 : (float) $user->{User::CREDIT_LIMIT};

        return success([
            'summary' => [
                'debt_balance' => (string) $balance,
                'credit_limit' => (string) $limit,
                // منفی یعنی از سقف گذشته — همان چیزی که سفارش و برداشت را
                // مسدود می‌کند، پس باید صریح باشد نه محاسبه‌ی فرانت.
                'headroom' => (string) ($limit - $balance),
                'is_blocked' => $debtEngine->isInDebt($userId),
            ],
            'entries' => $rows,
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
        ]);
    }
}
