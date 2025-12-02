<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDepositReceiptRequest;
use App\Http\Requests\WalletDepositRequest;
use App\Http\Requests\WalletWithdrawalRequest;
use App\Http\Resources\WalletResource;
use App\Http\Resources\WalletTransactionResource;
use App\Models\UserBankAccount;
use App\Models\UserProfile;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class WalletController extends Controller
{
    // =========================================================================
    //  بخش ۱: کیف پول (Wallet & Transactions)
    // =========================================================================

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

        $query = WalletTransaction::where(WalletTransaction::WALLET_ID, $wallet->id)->latest();

        if ($request->filled('type')) {
            match ($request->input('type')) {
                'deposit'  => $query->where(WalletTransaction::TYPE, WalletTransaction::TYPE_DEPOSIT),
                'withdraw' => $query->where(WalletTransaction::TYPE, WalletTransaction::TYPE_WITHDRAW),
                'fee'      => $query->whereIn(WalletTransaction::TYPE, [WalletTransaction::TYPE_FEE_HOLD, WalletTransaction::TYPE_FEE_RELEASE]),
                default    => null,
            };
        }

        if ($request->filled('status')) {
            $query->where(WalletTransaction::STATUS, $request->input('status'));
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

        return match ($method) {
            'bank_transfer' => success([
                'transaction_id' => $transaction->id,
                'type'           => 'manual_payment',
                'message'        => __('wallet.deposit_manual_info', ['amount' => number_format($amount)]),
                'upload_url'     => route('wallet.deposit.upload', ['transaction' => $transaction->id])
            ], __('wallet.deposit_created')),

            'shaparak' => success([
                'transaction_id' => $transaction->id,
                'type'           => 'gateway_redirect',
                'redirect_url'   => "https://shaparak.example.com/payment/{$transaction->id}",
            ], __('wallet.redirect_to_gateway')),

            'podium' => success([
                'transaction_id' => $transaction->id,
                'type'           => 'gateway_redirect',
                'redirect_url'   => "https://podium.example.com/payment/{$transaction->id}",
            ], __('wallet.redirect_to_gateway')),

            default => error(__('wallet.invalid_payment_method'), [], 400),
        };
    }

    /**
     * آپلود رسید برای تراکنشی که قبلاً با requestDeposit ایجاد شده
     */
    public function uploadDepositReceipt(Request $request, WalletTransaction $transaction): JsonResponse
    {
        $user = Auth::user();

        if ($transaction->wallet->{Wallet::USER_ID} !== $user->id) {
            return error(__('auth.forbidden'), [], 403);
        }

        $meta = $transaction->{WalletTransaction::META};
        $isBankTransfer = ($meta['payment_method'] ?? '') === 'bank_transfer';

        if (
            $transaction->{WalletTransaction::TYPE} !== WalletTransaction::TYPE_DEPOSIT ||
            $transaction->{WalletTransaction::STATUS} !== WalletTransaction::STATUS_PENDING ||
            !$isBankTransfer
        ) {
            return error(__('wallet.invalid_upload_transaction'), [], 400);
        }

        $request->validate([
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']
        ]);

        $path = $request->file('receipt')->store('wallet_receipts', 'public');

        $transaction->update([
            WalletTransaction::DESCRIPTION => $transaction->{WalletTransaction::DESCRIPTION} . ' | رسید آپلود شد',
            WalletTransaction::META => array_merge($meta ?? [], ['receipt_path' => $path])
        ]);

        return success(new WalletTransactionResource($transaction), __('wallet.receipt_uploaded'));
    }

    public function requestWithdrawal(WalletWithdrawalRequest $request): JsonResponse
    {
        $user = Auth::user();
        $wallet = $user->wallet()->firstOrCreate();
        $validated = $request->validated();

        $amount = $validated['amount'];
        $bankAccount = UserBankAccount::find($validated['user_bank_account_id']);

        if (!$bankAccount) {
            return error(__('wallet.bank_account_not_found'), [], 404);
        }

        try {
            $transaction = DB::transaction(function () use ($wallet, $user, $amount, $bankAccount) {
                // کسر از موجودی (قفل کردن برای جلوگیری از Race Condition)
                $wallet->lockForUpdate()->decrement(Wallet::BALANCE, $amount);

                $desc = __('wallet.withdrawal_description', [
                    'shaba' => $bankAccount->{UserBankAccount::SHABA}
                ]);

                return $wallet->transactions()->create([
                    WalletTransaction::AMOUNT      => $amount,
                    WalletTransaction::TYPE        => WalletTransaction::TYPE_WITHDRAW,
                    WalletTransaction::STATUS      => WalletTransaction::STATUS_PENDING,
                    WalletTransaction::DESCRIPTION => $desc,
                    WalletTransaction::META        => [
                        'shaba'     => $bankAccount->{UserBankAccount::SHABA},
                        'bank_name' => $bankAccount->{UserBankAccount::BANK_NAME},
                        'holder'    => $bankAccount->{UserBankAccount::ACCOUNT_HOLDER_NAME},
                    ]
                ]);
            }, 5);

            return success(new WalletTransactionResource($transaction), __('wallet.withdrawal_created'));

        } catch (\Throwable $e) {
            return error(__('global.operation_failed'), ['debug' => $e->getMessage()], 500);
        }
    }


    // =========================================================================
    //  بخش ۲: فیش‌های پرداختی (Payment Receipts - Tab)
    // =========================================================================

    public function getReceipts(Request $request): JsonResponse
    {
        $user = Auth::user();
        $wallet = $user->wallet;

        // دریافت تراکنش‌هایی که از نوع واریز فیشی هستند
        $receipts = $wallet->transactions()
            ->where('type', WalletTransaction::TYPE_DEPOSIT)
            ->whereJsonContains('meta->payment_method', 'bank_transfer')
            ->latest()
            ->paginate(20);

        return success(WalletTransactionResource::collection($receipts), __('wallet.receipts_list'));
    }

    public function storeReceipt(StoreDepositReceiptRequest $request): JsonResponse
    {
        $user = Auth::user();
        $wallet = $user->wallet()->firstOrCreate();
        $data = $request->validated();

        $path = $request->file('receipt_image')->store('wallet_receipts', 'public');

        $metaData = [
            'payment_method' => 'bank_transfer',
            'receipt_info' => [
                'receipt_number' => $data['receipt_number'],
                'bank_name'      => $data['bank_name'],
                'payment_date'   => $data['payment_date'],
                'image_path'     => $path,
            ]
        ];

        $desc = __('wallet.receipt_description', [
            'number' => $data['receipt_number'],
            'bank'   => $data['bank_name']
        ]);
        if (!empty($data['description'])) {
            $desc .= ' - ' . $data['description'];
        }

        $transaction = $wallet->transactions()->create([
            WalletTransaction::AMOUNT      => $data['amount'],
            WalletTransaction::TYPE        => WalletTransaction::TYPE_DEPOSIT,
            WalletTransaction::STATUS      => WalletTransaction::STATUS_PENDING,
            WalletTransaction::DESCRIPTION => $desc,
            WalletTransaction::META        => $metaData
        ]);

        return success(new WalletTransactionResource($transaction), __('wallet.receipt_created'));
    }

    public function deleteReceipt(WalletTransaction $transaction): JsonResponse
    {
        if ($transaction->wallet->user_id !== Auth::id()) {
            return error(__('auth.forbidden'), [], 403);
        }

        // فقط فیش‌های در انتظار بررسی قابل حذف هستند
        if ($transaction->status !== WalletTransaction::STATUS_PENDING) {
            return error(__('wallet.receipt_delete_forbidden'), [], 400);
        }

        $transaction->delete();

        return success([], __('wallet.receipt_deleted'));
    }
}
