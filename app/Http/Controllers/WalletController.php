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
use App\Http\Requests\WalletDepositRequest;
use App\Http\Requests\WalletWithdrawalRequest;
use App\Models\UserBankAccount;
use App\Models\UserProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class WalletController extends Controller
{
    /**
     * دریافت موجودی کیف پول
     */
    public function getWallet(): JsonResponse
    {
        $user = Auth::user();
        $wallet = $user->wallet()->firstOrCreate();

        return success(new WalletResource($wallet), 'موجودی کیف پول دریافت شد.');
    }

    /**
     * دریافت تاریخچه تراکنش‌ها (با فیلتر)
     */
    public function getTransactions(Request $request): JsonResponse
    {
        $user = Auth::user();
        $wallet = $user->wallet()->firstOrCreate();
        $walletId = $wallet->id;

        $query = WalletTransaction::where('wallet_id', $walletId)
            ->latest();

        if ($request->filled('type')) {
            match ($request->input('type')) {
                'deposit'  => $query->where('type', WalletTransaction::TYPE_DEPOSIT),
                'withdraw' => $query->where('type', WalletTransaction::TYPE_WITHDRAW),
                'fee'      => $query->whereIn('type', [WalletTransaction::TYPE_FEE_HOLD, WalletTransaction::TYPE_FEE_RELEASE]),
                default    => null,
            };
        }
        // فیلتر وضعیت
        if ($request->filled('status')) {
            match ($request->input('status')) {
                'confirmed' => $query->where('status', WalletTransaction::STATUS_CONFIRMED),
                'pending'   => $query->where('status', WalletTransaction::STATUS_PENDING),
                'failed'    => $query->where('status', WalletTransaction::STATUS_FAILED),
                default     => null,
            };
        }
        // فیلتر بازه زمانی
        if ($request->filled('range')) {
            $now = Carbon::now();
            match ($request->input('range')) {
                '1_month'  => $query->where('created_at', '>=', $now->subMonth()),
                '3_months' => $query->where('created_at', '>=', $now->subMonths(3)),
                '1_year'   => $query->where('created_at', '>=', $now->subYear()),
                default    => null,
            };
        }

        $transactions = $query->paginate(10);

        return success(
            WalletTransactionResource::collection($transactions)->response()->getData(true),
            'تاریخچه تراکنش‌ها دریافت شد.'
        );
    }
    public function requestDeposit(WalletDepositRequest $request): JsonResponse
    {
        $user = Auth::user();
        $wallet = $user->wallet()->firstOrCreate();
        $validated = $request->validated();
        $amount = $validated['amount'];
        $method = $validated['payment_method'];

        $transaction = $wallet->transactions()->create([
            'amount'      => $amount,
            'type'        => WalletTransaction::TYPE_DEPOSIT,
            'status'      => WalletTransaction::STATUS_PENDING,
            'description' => 'درخواست افزایش موجودی توسط کاربر',
            'meta'        => ['payment_method' => $method]
        ]);

        switch ($method) {

            case 'bank_transfer':
                $paymentInfo = [
                    'transaction_id' => $transaction->id,
                    'type' => 'manual_payment',
                    'message' => 'درخواست شما ثبت شد. لطفاً مبلغ ' . number_format($amount) . ' تومان را به حساب ... واریز و رسید را بارگذاری نمایید.',

                    // 🚀 3. اصلاحیه اصلی اینجاست (ارسال پارامتر transaction)
                    'upload_url' => route('wallet.deposit.upload', ['transaction' => $transaction->id])
                ];
                return success($paymentInfo, 'درخواست واریز ثبت شد.');

            case 'shaparak':
                $redirectUrl = 'https://shaparak.example.com/payment/' . $transaction->id; // Placeholder
                $paymentInfo = [
                    'transaction_id' => $transaction->id,
                    'type' => 'gateway_redirect',
                    'redirect_url' => $redirectUrl,
                ];
                return success($paymentInfo, 'در حال انتقال به درگاه پرداخت...');

            case 'podium':
                $redirectUrl = 'https://podium.example.com/payment/' . $transaction->id; // Placeholder
                $paymentInfo = [
                    'transaction_id' => $transaction->id,
                    'type' => 'gateway_redirect',
                    'redirect_url' => $redirectUrl,
                ];
                return success($paymentInfo, 'در حال انتقال به درگاه پرداخت پادیوم...');
        }

        return error('متد پرداخت نامعتبر است.', [], 400); // Failsafe
    }

    /**
     * 🚀 جدید: آپلود فیش واریزی برای تراکنش‌های 'bank_transfer'
     */
    public function uploadDepositReceipt(Request $request, WalletTransaction $transaction): JsonResponse
    {
        $user = Auth::user();

        if ($transaction->wallet->user_id !== $user->id) {
            return error('شما مجاز به این کار نیستید.', [], 403);
        }

        if (
            $transaction->type !== WalletTransaction::TYPE_DEPOSIT ||
            $transaction->status !== WalletTransaction::STATUS_PENDING ||
            data_get($transaction->meta, 'payment_method') !== 'bank_transfer'
        ) {
            return error('امکان بارگذاری رسید برای این تراکنش وجود ندارد.', [], 400);
        }

        $validated = $request->validate([
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048']
        ]);

        $path = $validated['receipt']->store('wallet_receipts', 'public');

        $transaction->update([
            'description' => $transaction->description . ' | رسید بارگذاری شد.',
            'meta' => array_merge($transaction->meta ?? [], ['receipt_path' => $path])
        ]);

        return success(
            new WalletTransactionResource($transaction),
            'رسید شما با موفقیت بارگذاری شد و در انتظار تایید ادمین است.'
        );
    }

    /**
     * ثبت درخواست برداشت از کیف پول
     */
    public function requestWithdrawal(WalletWithdrawalRequest $request): JsonResponse
    {
        $user = Auth::user();
        $wallet = $user->wallet()->firstOrCreate();
        $validated = $request->validated();

        $amount = $validated['amount'];
        $bankAccountId = $validated['user_bank_account_id'];
        $bankAccount = UserBankAccount::find($bankAccountId);
        if (!$bankAccount) {
            return error('حساب بانکی یافت نشد.', [], 404);
        }
        $shaba = $bankAccount->{UserBankAccount::SHABA};

        try {
            $transaction = DB::transaction(function () use ($wallet, $user, $amount, $shaba, $bankAccount) {

                $wallet->lockForUpdate()->decrement('balance', $amount);

                $transaction = $wallet->transactions()->create([
                    'amount'      => $amount,
                    'type'        => WalletTransaction::TYPE_WITHDRAW,
                    'status'      => WalletTransaction::STATUS_PENDING,
                    'description' => 'درخواست برداشت به شبا: ' . $shaba . ' (بانک: ' . $bankAccount->{UserBankAccount::BANK_NAME} . ')',
                    'meta'        => [
                        'shaba'      => $shaba,
                        'bank_name'  => $bankAccount->{UserBankAccount::BANK_NAME},
                        'account_holder_name' => $bankAccount->{UserBankAccount::ACCOUNT_HOLDER_NAME},
                        'first_name' => $user->profile->{UserProfile::NAME} ?? '',
                        'last_name'  => $user->profile->{UserProfile::FAMILY} ?? '',
                    ]
                ]);

                return $transaction;
            }, 5);
        } catch (\Throwable $e) {
            return error('خطا در ثبت درخواست. لطفاً دوباره تلاش کنید.', ['db_error' => $e->getMessage()], 500);
        }

        // 4. TODO: ارسال درخواست به Podium (یا صف برای بررسی ادمین)

        return success(
            new WalletTransactionResource($transaction),
            'درخواست برداشت شما ثبت شد و پس از بررسی ادمین، واریز خواهد شد.'
        );
    }
}
