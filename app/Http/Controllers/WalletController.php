<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\WalletResource;
use App\Http\Resources\WalletTransactionResource;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
// 🚀 ایمپورت کردن Request هایی که ساختیم
use App\Http\Requests\WalletDepositRequest;
use App\Http\Requests\WalletWithdrawalRequest;
use App\Models\UserProfile;
use Illuminate\Http\JsonResponse;

class WalletController extends Controller
{
    /**
     * دریافت موجودی کیف پول (قابل برداشت و رزرو شده)
     * مطابق با UI کارت کیف پول
     */
    public function getWallet(): JsonResponse
    {
        $user = Auth::user();
        $wallet = $user->wallet; // (رابطه withDefault در مدل User، آن را می‌سازد)

        return success(new WalletResource($wallet), 'موجودی کیف پول دریافت شد.');
    }

    /**
     * دریافت تاریخچه تراکنش‌ها (با قابلیت فیلتر)
     * مطابق با UI لیست تراکنش‌ها
     */
    public function getTransactions(Request $request): JsonResponse
    {
        $user = Auth::user();
        $walletId = $user->wallet->id;

        $query = WalletTransaction::where('wallet_id', $walletId)
            ->latest(); // مرتب‌سازی بر اساس جدیدترین

        // 1. فیلتر بر اساس نوع تراکنش
        if ($request->filled('type')) {
            match ($request->input('type')) {
                'withdraw' => $query->where('type', WalletTransaction::TYPE_WITHDRAW),
                'deposit'  => $query->where('type', WalletTransaction::TYPE_DEPOSIT),
                'fee'      => $query->whereIn('type', [WalletTransaction::TYPE_FEE_HOLD, WalletTransaction::TYPE_FEE_RELEASE]),
                default    => null,
            };
        }

        // 2. فیلتر بر اساس وضعیت
        if ($request->filled('status')) {
            match ($request->input('status')) {
                'confirmed' => $query->where('status', WalletTransaction::STATUS_CONFIRMED),
                'pending'   => $query->where('status', WalletTransaction::STATUS_PENDING),
                default     => null,
            };
        }

        // 3. فیلتر بر اساس بازه زمانی (فعلا کامنت)
        if ($request->filled('from_date')) {
            // $gregorianFrom = ...
            // $query->where('created_at', '>=', $gregorianFrom);
        }
        if ($request->filled('to_date')) {
            // $gregorianTo = ...
            // $query->where('created_at', '<=', $gregorianTo);
        }

        $transactions = $query->paginate(10); // صفحه‌بندی

        // 🚀 2. اصلاح: بسته‌بندی خروجی با Helper `success`
        // این کار باعث می‌شود خروجی نهایی یک JsonResponse باشد
        return success(
            WalletTransactionResource::collection($transactions)->response()->getData(true),
            'تاریخچه تراکنش‌ها دریافت شد.'
        );
    }

    /**
     * ثبت درخواست افزایش موجودی (واریز)
     * (مطابق با UI افزودن موجودی)
     */
    public function requestDeposit(WalletDepositRequest $request): JsonResponse
    {
        $user = Auth::user();
        $wallet = $user->wallet;
        $amount = $request->validated('amount');

        // 1. یک تراکنش "در انتظار" می‌سازیم.
        $transaction = $wallet->transactions()->create([
            'amount'      => $amount,
            'type'        => WalletTransaction::TYPE_DEPOSIT,
            'status'      => WalletTransaction::STATUS_PENDING,
            'description' => 'درخواست افزایش موجودی توسط کاربر',
        ]);

        // 2. TODO: اطلاعات حساب مقصد (الیت زون) را به کاربر نشان دهید
        $paymentInfo = [
            'message' => 'درخواست شما ثبت شد. لطفاً مبلغ ' . number_format($amount) . ' تومان را به حساب ... واریز و رسید را بارگذاری نمایید.',
            'transaction_id' => $transaction->id,
        ];

        return success($paymentInfo, 'درخواست واریز ثبت شد.');
    }

    /**
     * ثبت درخواست برداشت از کیف پول
     * (مطابق با UI برداشت از کیف پول)
     */
    public function requestWithdrawal(WalletWithdrawalRequest $request): JsonResponse
    {
        $user = Auth::user();
        $wallet = $user->wallet;
        $validated = $request->validated();
        $amount = $validated['amount'];
        $shaba = $validated['shaba'];

        // 1. موجودی قابل برداشت کاربر را قفل می‌کنیم
        try {
            $transaction = DB::transaction(function () use ($wallet, $user, $amount, $shaba) {

                // 2. قفل کردن ردیف کیف پول و کاهش موجودی
                $wallet->lockForUpdate()->decrement('balance', $amount);

                // 3. ثبت تراکنش برداشت در حالت "در انتظار"
                $transaction = $wallet->transactions()->create([
                    'amount'      => $amount,
                    'type'        => WalletTransaction::TYPE_WITHDRAW,
                    'status'      => WalletTransaction::STATUS_PENDING,
                    'description' => 'درخواست برداشت توسط کاربر به شبا: ' . $shaba,
                    'meta'        => [
                        'shaba'      => $shaba,
                        'first_name' => $user->profile->{UserProfile::NAME} ?? '',
                        'last_name'  => $user->profile->{UserProfile::FAMILY} ?? '',
                    ]
                ]);

                return $transaction;
            }, 5); // 5 بار تلاش

        } catch (\Throwable $e) {
            return error('خطا در ثبت درخواست. لطفاً دوباره تلاش کنید.', ['db_error' => $e->getMessage()], 500);
        }

        // 4. TODO: (در آینده) ارسال درخواست به Podium یا صف ادمین

        return success(
            new WalletTransactionResource($transaction),
            'درخواست برداشت شما ثبت شد و پس از بررسی ادمین، واریز خواهد شد.'
        );
    }
}
