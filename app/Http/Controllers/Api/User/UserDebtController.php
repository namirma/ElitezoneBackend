<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\User;

use App\Actions\Financial\DebitWalletAction;
use App\Actions\Financial\FundWalletAction;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\DebtEntry;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Financial\DebtEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

    /**
     * POST /api/profile/debts/repay — کاربر بدهی‌اش را از کیف پول می‌پردازد.
     *
     * ⚠️ **بدون این، بدهی می‌توانست دائمی شود.** بدهی هم ثبت سفارش را می‌بست هم
     * برداشت را، ولی تنها راه‌های تسویه‌اش سه مسیرِ رویدادمحور بودند (اضافه‌بار،
     * خسارت عودت، سقف خسارت). کاربری که بدهی‌اش از یکی از آن‌ها آمده بود ولی آن
     * رویداد دیگر تکرار نمی‌شد، **هیچ کاری نمی‌توانست بکند** — نه خودش، نه
     * ادمین (که فقط `credit_limit` را می‌تواند بگذارد، نه مانده را صفر کند).
     *
     * ترتیب لِگ‌ها همان `settleShortfall` است: اول کسر از کیف پول، بعد بستن
     * دفتر. عکسش یعنی بدهی صفر شود و بعد کسر با موجودیِ ناکافی throw کند.
     *
     * ‏`creditSystem` هم می‌خورد چون بدهی یعنی شرکت پول را جلو انداخته بود؛
     * بازپرداخت باید به همان کیف پولِ سازه برگردد وگرنه دفترِ کیف پول‌ها
     * نامتوازن می‌ماند و `financial:doctor` آن را «پول خلق‌شده» می‌بیند.
     */
    public function repay(
        Request $request,
        DebtEngine $debtEngine,
        DebitWalletAction $debit,
        FundWalletAction $fund,
    ): JsonResponse {
        /** @var User|null $user */
        $user = User::whereKey((int) Auth::id())->first();

        if ($user === null) {
            throw new BusinessException(__('orders.internal_order_not_found'), 404);
        }

        $balance = $debtEngine->balanceFor((int) $user->id);

        if ($balance <= 0.0) {
            throw new BusinessException(__('orders.debt_nothing_to_repay'), 422);
        }

        $data = $request->validate(
            ['amount' => ['nullable', 'numeric', 'min:1']],
            ['amount.min' => __('orders.debt_repay_amount_min')],
        );

        // بدون مبلغ یعنی «همه‌اش». بیشتر از مانده به مانده clamp می‌شود تا کاربر
        // نتواند بیش از بدهی‌اش بپردازد و طلبکارِ سازه شود.
        $amount = min((float) ($data['amount'] ?? $balance), $balance);

        // ‏`DebitWalletAction`/`FundWalletAction` و نه `WalletTransactionEngine`:
        // موتور order-scoped است و بدهی می‌تواند ردیفِ بی‌سفارش هم داشته باشد
        // (تعدیل دستیِ بک‌آفیس). این دو اکشن دقیقاً برای همین حالت‌اند.
        $systemUser = User::whereKey((int) config('financial.system_user_id', 1))->first();

        DB::transaction(function () use ($user, $systemUser, $amount, $debit, $fund, $debtEngine) {
            // ترتیب اجباری: اول کسر از کیف پول، بعد بستن دفتر. عکسش یعنی بدهی
            // صفر شود و بعد کسر با موجودیِ ناکافی throw کند.
            $debit->execute($user, $amount, __('orders.debt_repay_description'), [
                'kind' => 'debt_repayment',
            ]);

            // بدهی یعنی شرکت پول را جلو انداخته بود؛ بازپرداخت باید به همان
            // کیف پول سازه برگردد وگرنه لِگِ کیف پول‌ها نامتوازن می‌ماند.
            // ⚠️ `STATUS_CONFIRMED` صریح، و این پارامتر تا امروز اصلاً وجود
            // نداشت. پیش‌فرضِ `FundWalletAction` یک ردیفِ `deposit/pending`
            // می‌ساخت که موجودی را **تکان نمی‌داد** — یعنی کیف پولِ بدهکار
            // فوراً کسر می‌شد، کیف پولِ سازه چیزی نمی‌گرفت، و بدهی هم بسته
            // می‌شد: پول از سیستم ناپدید می‌شد. اینجا بازپرداخت است نه درخواستِ
            // واریز، پس اعتبار باید همان لحظه بنشیند.
            if ($systemUser !== null) {
                $fund->execute(
                    $systemUser, $amount, WalletTransaction::METHOD_BANK_TRANSFER,
                    __('orders.debt_repay_description'), null, ['kind' => 'debt_repayment'],
                    WalletTransaction::STATUS_CONFIRMED
                );
            }

            $debtEngine->repay((int) $user->id, $amount, null, __('orders.debt_repay_description'));
        });

        return success([
            'repaid' => (string) $amount,
            'debt_balance' => (string) $debtEngine->balanceFor((int) $user->id),
        ], __('orders.debt_repaid'));
    }
}
