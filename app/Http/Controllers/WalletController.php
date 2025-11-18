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
use App\Models\Wallet;

class WalletController extends Controller
{
    public function getWallet(): JsonResponse
    {
        $user = Auth::user();
        $wallet = $user->wallet()->firstOrCreate();

        return success(new WalletResource($wallet), __('wallet.balance_received'));
    }

    public function getTransactions(Request $request): JsonResponse
    {
        $user = Auth::user();
        $wallet = $user->wallet()->firstOrCreate();
        $walletId = $wallet->id;

        $query = WalletTransaction::where(WalletTransaction::WALLET_ID, $walletId)
            ->latest();

        if ($request->filled('type')) {
            match ($request->input('type')) {
                'deposit'  => $query->where(WalletTransaction::TYPE, WalletTransaction::TYPE_DEPOSIT),
                'withdraw' => $query->where(WalletTransaction::TYPE, WalletTransaction::TYPE_WITHDRAW),
                'fee'      => $query->whereIn(WalletTransaction::TYPE, [WalletTransaction::TYPE_FEE_HOLD, WalletTransaction::TYPE_FEE_RELEASE]),
                default    => null,
            };
        }

        if ($request->filled('status')) {
            match ($request->input('status')) {
                'confirmed' => $query->where(WalletTransaction::STATUS, WalletTransaction::STATUS_CONFIRMED),
                'pending'   => $query->where(WalletTransaction::STATUS, WalletTransaction::STATUS_PENDING),
                'failed'    => $query->where(WalletTransaction::STATUS, WalletTransaction::STATUS_FAILED),
                default     => null,
            };
        }

        if ($request->filled('range')) {
            $now = Carbon::now();
            match ($request->input('range')) {
                '1_month'  => $query->where(WalletTransaction::CREATED_AT, '>=', $now->subMonth()),
                '3_months' => $query->where(WalletTransaction::CREATED_AT, '>=', $now->subMonths(3)),
                '1_year'   => $query->where(WalletTransaction::CREATED_AT, '>=', $now->subYear()),
                default    => null,
            };
        }

        $transactions = $query->paginate(10);

        return success(
            WalletTransactionResource::collection($transactions)->response()->getData(true),
            __('wallet.transactions_received')
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
            WalletTransaction::AMOUNT      => $amount,
            WalletTransaction::TYPE        => WalletTransaction::TYPE_DEPOSIT,
            WalletTransaction::STATUS      => WalletTransaction::STATUS_PENDING,
            WalletTransaction::DESCRIPTION => __('wallet.deposit_description'),
            WalletTransaction::META        => ['payment_method' => $method]
        ]);

        switch ($method) {

            case 'bank_transfer':
                $paymentInfo = [
                    'transaction_id' => $transaction->id,
                    'type' => 'manual_payment',
                    'message' => __('wallet.deposit_manual_info', ['amount' => number_format($amount)]),
                    'upload_url' => route('wallet.deposit.upload', ['transaction' => $transaction->id])
                ];
                return success($paymentInfo, __('wallet.deposit_created'));

            case 'shaparak':
                $redirectUrl = 'https://shaparak.example.com/payment/' . $transaction->id;
                $paymentInfo = [
                    'transaction_id' => $transaction->id,
                    'type' => 'gateway_redirect',
                    'redirect_url' => $redirectUrl,
                ];
                return success($paymentInfo, __('wallet.redirect_to_gateway'));

            case 'podium':
                $redirectUrl = 'https://podium.example.com/payment/' . $transaction->id;
                $paymentInfo = [
                    'transaction_id' => $transaction->id,
                    'type' => 'gateway_redirect',
                    'redirect_url' => $redirectUrl,
                ];
                return success($paymentInfo, __('wallet.redirect_to_gateway'));
        }

        return error(__('wallet.invalid_payment_method'), [], 400);
    }

    public function uploadDepositReceipt(Request $request, WalletTransaction $transaction): JsonResponse
    {
        $user = Auth::user();

        if ($transaction->wallet->{Wallet::USER_ID} !== $user->id) {
            return error(__('auth.forbidden'), [], 403);
        }

        $meta = $transaction->{WalletTransaction::META};
        $paymentMethod = is_array($meta) ? ($meta['payment_method'] ?? null) : null;

        if (
            $transaction->{WalletTransaction::TYPE} !== WalletTransaction::TYPE_DEPOSIT ||
            $transaction->{WalletTransaction::STATUS} !== WalletTransaction::STATUS_PENDING ||
            $paymentMethod !== 'bank_transfer'
        ) {
            return error(__('wallet.invalid_upload_transaction'), [], 400);
        }

        $validated = $request->validate([
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048']
        ]);

        $path = $validated['receipt']->store('wallet_receipts', 'public');

        $transaction->update([
            WalletTransaction::DESCRIPTION => $transaction->{WalletTransaction::DESCRIPTION} . ' | رسید بارگذاری شد.',
            WalletTransaction::META => array_merge($meta ?? [], ['receipt_path' => $path])
        ]);

        return success(
            new WalletTransactionResource($transaction),
            __('wallet.receipt_uploaded')
        );
    }

    public function requestWithdrawal(WalletWithdrawalRequest $request): JsonResponse
    {
        $user = Auth::user();
        $wallet = $user->wallet()->firstOrCreate();
        $validated = $request->validated();

        $amount = $validated['amount'];
        $bankAccountId = $validated['user_bank_account_id'];

        $bankAccount = UserBankAccount::find($bankAccountId);
        if (!$bankAccount) {
            return error(__('wallet.bank_account_not_found'), [], 404);
        }
        $shaba = $bankAccount->{UserBankAccount::SHABA};
        $bankName = $bankAccount->{UserBankAccount::BANK_NAME};
        $accountHolder = $bankAccount->{UserBankAccount::ACCOUNT_HOLDER_NAME};

        try {
            $transaction = DB::transaction(function () use ($wallet, $user, $amount, $shaba, $bankName, $accountHolder) {

                $wallet->lockForUpdate()->decrement(Wallet::BALANCE, $amount);

                $desc = __('wallet.withdrawal_description', ['shaba' => $shaba, 'bank' => $bankName]);

                $transaction = $wallet->transactions()->create([
                    WalletTransaction::AMOUNT      => $amount,
                    WalletTransaction::TYPE        => WalletTransaction::TYPE_WITHDRAW,
                    WalletTransaction::STATUS      => WalletTransaction::STATUS_PENDING,
                    WalletTransaction::DESCRIPTION => $desc,
                    WalletTransaction::META        => [
                        'shaba'      => $shaba,
                        'bank_name'  => $bankName,
                        'account_holder_name' => $accountHolder,
                        'first_name' => $user->profile->{UserProfile::NAME} ?? '',
                        'last_name'  => $user->profile->{UserProfile::FAMILY} ?? '',
                    ]
                ]);

                return $transaction;
            }, 5);
        } catch (\Throwable $e) {
            return error(__('global.operation_failed'), ['db_error' => $e->getMessage()], 500);
        }

        return success(
            new WalletTransactionResource($transaction),
            __('wallet.withdrawal_created')
        );
    }
}
