<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Content\AbandonedCartResource;
use App\Models\AbandonedCart;
use App\Models\DebtEntry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * گزارش‌های بک‌آفیس — فاز ۱۳، بند ۷.۶ سند.
 *
 * دو گزارش: سبدهای رهاشده و بدهی. هر دو صرفاً خواندنی‌اند؛ هیچ‌کدام چیزی
 * نمی‌نویسند — نوشتنِ بدهی فقط از راه `DebtEngine` است.
 */
class AdminDebtController extends Controller
{
    /**
     * GET /admin/abandoned-carts — بند ۷.۶.
     *
     * اسنپ‌شاتِ سبدهایی که مهلتشان تمام شد و پاک شدند (فاز ۲). این تنها
     * جایی است که می‌شود فهمید چه کسی چه چیزی را نیمه‌کاره رها کرد — خودِ
     * `carts` پاک شده است.
     */
    public function abandonedCarts(Request $request): JsonResponse
    {
        $query = AbandonedCart::query()->with(['user.profile', 'supplier.profile']);

        if ($from = $request->query('from')) {
            $query->whereDate(AbandonedCart::EXPIRED_AT, '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate(AbandonedCart::EXPIRED_AT, '<=', $to);
        }

        if ($supplierId = $request->integer('supplier_user_id')) {
            $query->where(AbandonedCart::SUPPLIER_USER_ID, $supplierId);
        }

        $carts = $query->latest(AbandonedCart::EXPIRED_AT)->paginate(
            min(100, max(10, $request->integer('per_page', 20)))
        );

        return success(
            AbandonedCartResource::collection($carts)->response()->getData(true),
            __('admin.reports.abandoned_carts_received')
        );
    }

    /**
     * GET /admin/debts — بند ۷.۶.
     *
     * فهرست بدهکاران به‌علاوه‌ی سرجمع. عمداً از `users.debt_balance` خوانده
     * می‌شود و نه از جمعِ زنده‌ی دفتر: اگر این دو با هم فرق داشته باشند یک
     * باگ است و گزارش باید همان چیزی را نشان دهد که گاردهای سفارش و برداشت
     * به آن تکیه می‌کنند.
     */
    public function debts(Request $request): JsonResponse
    {
        $query = User::query()
            ->where(User::DEBT_BALANCE, '>', 0)
            ->with('profile');

        if ($search = $request->query('search')) {
            $query->where(User::MOBILE, 'like', "%{$search}%");
        }

        $debtors = $query
            ->orderByDesc(User::DEBT_BALANCE)
            ->paginate(min(100, max(10, $request->integer('per_page', 20))));

        // ‏`map` و نه `transform`: دومی باید همان نوعِ مدل را برگرداند و
        // اینجا خروجی آرایه است.
        $rows = $debtors->getCollection()->map(fn (User $user) => [
            'user_id' => $user->id,
            'mobile' => $user->{User::MOBILE},
            'full_name' => $user->full_name,
            'debt_balance' => (string) $user->{User::DEBT_BALANCE},
            'credit_limit' => (string) $user->{User::CREDIT_LIMIT},
            // منفی یعنی از سقف عبور کرده — همان حالتی که سفارش تازه را متوقف
            // می‌کند، پس بک‌آفیس باید بتواند سرِ یک نگاه ببیندش.
            'headroom' => (string) ((float) $user->{User::CREDIT_LIMIT} - (float) $user->{User::DEBT_BALANCE}),
        ])->values()->all();

        return success([
            'debtors' => $rows,
            'meta' => [
                'current_page' => $debtors->currentPage(),
                'last_page' => $debtors->lastPage(),
                'per_page' => $debtors->perPage(),
                'total' => $debtors->total(),
            ],
            'summary' => [
                'total_debt' => (string) User::where(User::DEBT_BALANCE, '>', 0)->sum(User::DEBT_BALANCE),
                'debtor_count' => User::where(User::DEBT_BALANCE, '>', 0)->count(),
                'by_type' => DebtEntry::query()
                    ->selectRaw('type, SUM(amount) as total')
                    ->groupBy(DebtEntry::TYPE)
                    ->pluck('total', DebtEntry::TYPE),
            ],
        ], __('admin.reports.debts_received'));
    }

    /**
     * GET /admin/users/{user}/debt-entries — دفترِ یک بدهکار.
     *
     * بدون این، مانده یک عددِ بی‌توضیح است و بک‌آفیس نمی‌تواند بگوید از کجا
     * آمده — همان دلیلی که کیف پول تاریخچه دارد.
     */
    public function userDebtEntries(User $user): JsonResponse
    {
        $entries = DebtEntry::query()
            ->where(DebtEntry::USER_ID, $user->id)
            ->latest(DebtEntry::ID)
            ->paginate(50);

        return success([
            'balance' => (string) $user->{User::DEBT_BALANCE},
            'entries' => $entries->items(),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'total' => $entries->total(),
            ],
        ], __('admin.reports.debt_entries_received'));
    }
}
