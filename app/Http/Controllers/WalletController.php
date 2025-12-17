<?php

namespace App\Http\Controllers;

use App\Actions\Financial\DebitWalletAction;
use App\Http\Requests\StoreDepositReceiptRequest;
use App\Http\Requests\WalletDepositRequest;
use App\Http\Requests\WalletWithdrawalRequest;
use App\Http\Resources\WalletResource;
use App\Http\Resources\WalletTransactionResource;
use App\Models\UserBankAccount;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WalletController extends Controller
{
    public function __construct(
        protected DebitWalletAction $debitWalletAction
    ) {}

    // =========================================================================
    //  بخش ۱: اطلاعات و تراکنش‌ها
    // =========================================================================

    public function getWallet(): JsonResponse
    {
        $user = Auth::user();
        $wallet = $user->wallet()->firstOrCreate([]);

        return success(new WalletResource($wallet), __('wallet.balance_received'));
    }

    public function getTransactions(Request $request): JsonResponse
    {
        $user = Auth::user();
        $wallet = $user->wallet()->firstOrCreate([]);

        $query = $wallet->transactions()->latest();

        // فیلترها (نوع، وضعیت، زمان)
        if ($request->filled('type')) {
            $type = $request->input('type');
            if ($type === 'deposit') $query->where('type', WalletTransaction::TYPE_DEPOSIT);
            elseif ($type === 'withdraw') $query->where('type', WalletTransaction::TYPE_WITHDRAW);
            elseif ($type === 'fee') $query->whereIn('type', [WalletTransaction::TYPE_FEE_HOLD, WalletTransaction::TYPE_FEE_RELEASE]);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('range')) {
            $now = now();
            match ($request->input('range')) {
                '1_month'  => $query->where('created_at', '>=', $now->subMonth()),
                '3_months' => $query->where('created_at', '>=', $now->subMonths(3)),
                '1_year'   => $query->where('created_at', '>=', $now->subYear()),
                default    => null,
            };
        }

        $transactions = $query->paginate($request->input('per_page', 10));

        return success(
            WalletTransactionResource::collection($transactions)->response()->getData(true),
            __('wallet.transactions_received')
        );
    }

    // =========================================================================
    //  بخش ۲: شارژ و واریز (Deposit & Upload)
    // =========================================================================

    public function requestDeposit(WalletDepositRequest $request): JsonResponse
    {
        $user = Auth::user();
        $wallet = $user->wallet()->firstOrCreate([]);
        $data = $request->validated();

        $transaction = $wallet->transactions()->create([
            'amount'      => $data['amount'],
            'type'        => WalletTransaction::TYPE_DEPOSIT,
            'status'      => WalletTransaction::STATUS_PENDING,
            'description' => __('wallet.deposit_description'),
            'meta'        => ['payment_method' => $data['payment_method']],
        ]);

        if ($data['payment_method'] === 'bank_transfer') {
            return success([
                'transaction_id' => $transaction->id,
                'type'           => 'manual_payment',
                'message'        => __('wallet.deposit_manual_info', ['amount' => number_format($data['amount'])]),
            ], __('wallet.deposit_created'));
        }

        // منطق درگاه آنلاین (Shaparak/Podium)
        $url = "https://dummy-gateway.com/pay/{$transaction->id}";
        return success([
            'transaction_id' => $transaction->id,
            'type'           => 'gateway_redirect',
            'redirect_url'   => $url,
        ], __('wallet.redirect_to_gateway'));
    }

    /**
     * آپلود فیش برای تراکنش از قبل ایجاد شده
     */
    public function uploadDepositReceipt(Request $request, WalletTransaction $transaction): JsonResponse
    {
        $user = Auth::user();

        if ($transaction->wallet->user_id !== $user->id) {
            return error(__('auth.forbidden'), [], 403);
        }

        $isBankTransfer = ($transaction->meta['payment_method'] ?? '') === 'bank_transfer';
        if (
            $transaction->type !== WalletTransaction::TYPE_DEPOSIT ||
            $transaction->status !== WalletTransaction::STATUS_PENDING ||
            !$isBankTransfer
        ) {
            return error(__('wallet.invalid_upload_transaction'), [], 400);
        }

        $request->validate(['receipt' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120']);

        $path = $request->file('receipt')->store('wallet_receipts', 'public');

        $transaction->update([
            'description' => $transaction->description . ' | رسید آپلود شد',
            'meta'        => array_merge($transaction->meta ?? [], ['receipt_path' => $path])
        ]);

        return success(new WalletTransactionResource($transaction), __('wallet.receipt_uploaded'));
    }

    /**
     * ثبت فیش جدید (مستقیم)
     */
    public function storeReceipt(StoreDepositReceiptRequest $request): JsonResponse
    {
        $user = Auth::user();
        $wallet = $user->wallet()->firstOrCreate([]);
        $data = $request->validated();

        $file = $request->file('receipt_image');

        $originalName = $file->getClientOriginalName();

        $path = $file->store('wallet_receipts', 'public');

        $metaData = [
            'payment_method' => 'bank_transfer',
            'receipt_path'   => $path,
            'receipt_info'   => [
                'receipt_number' => $data['receipt_number'],
                'bank_name'      => $data['bank_name'],
                'payment_date'   => $data['payment_date'],
                'original_name'  => $originalName,
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
            'amount'      => $data['amount'],
            'type'        => WalletTransaction::TYPE_DEPOSIT,
            'status'      => WalletTransaction::STATUS_PENDING,
            'description' => $desc,
            'meta'        => $metaData
        ]);

        return success(new WalletTransactionResource($transaction), __('wallet.receipt_created'));
    }

    // =========================================================================
    //  بخش ۳: دانلود فیش (قابلیت جدید)
    // =========================================================================

    /**
     * دانلود رسید آپلود شده
     * Route: GET /transactions/{transaction}/download
     */
    public function downloadReceipt(WalletTransaction $transaction): StreamedResponse|JsonResponse
    {
        if ($transaction->wallet->user_id !== Auth::id()) {
            return error(__('auth.forbidden'), [], 403);
        }

        $meta = $transaction->meta ?? [];
        $path = $meta['receipt_path'] ?? ($meta['receipt_info']['image_path'] ?? null);

        if (!$path || !Storage::disk('public')->exists($path)) {
            return error('فایل رسید یافت نشد.', [], 404);
        }

        $downloadName = $meta['receipt_info']['original_name'] ?? basename($path);

        return Storage::disk('public')->download($path, $downloadName);
    }

    // =========================================================================
    //  بخش ۴: لیست فیش‌ها و حذف
    // =========================================================================

    public function getReceipts(): JsonResponse
    {
        $user = Auth::user();
        $wallet = $user->wallet;

        if (!$wallet) {
            return success([], __('wallet.receipts_list'));
        }

        $receipts = $wallet->transactions()
            ->where('type', WalletTransaction::TYPE_DEPOSIT)
            ->whereJsonContains('meta->payment_method', 'bank_transfer')
            ->latest()
            ->paginate(20);

        return success(WalletTransactionResource::collection($receipts), __('wallet.receipts_list'));
    }

    public function deleteReceipt(WalletTransaction $transaction): JsonResponse
    {
        if ($transaction->wallet->user_id !== Auth::id()) {
            return error(__('auth.forbidden'), [], 403);
        }

        if ($transaction->status !== WalletTransaction::STATUS_PENDING) {
            return error(__('wallet.receipt_delete_forbidden'), [], 400);
        }
        $path = $transaction->meta['receipt_path'] ?? null;
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        $transaction->delete();

        return success([], __('wallet.receipt_deleted'));
    }

    // =========================================================================
    //  بخش ۵: برداشت وجه
    // =========================================================================

    public function requestWithdrawal(WalletWithdrawalRequest $request): JsonResponse
    {
        $user = Auth::user();
        $validated = $request->validated();
        $amount = (float) $validated['amount'];

        $lockKey = "wallet_withdraw_lock:user:{$user->id}";

        try {
            return Cache::lock($lockKey, 10)->block(10, function () use ($user, $validated, $amount) {

                $bankAccount = UserBankAccount::where('id', $validated['user_bank_account_id'])
                    ->where('user_id', $user->id)
                    ->first();

                if (!$bankAccount) {
                    return error(__('wallet.bank_account_not_found'), [], 404);
                }

                return DB::transaction(function () use ($user, $amount, $bankAccount) {
                    $transaction = $this->debitWalletAction->execute(
                        user: $user,
                        amount: $amount,
                        description: __('wallet.withdrawal_description', [
                            'shaba' => $bankAccount->shaba,
                            'bank'  => $bankAccount->bank_name
                        ]),
                        meta: [
                            'withdrawal_request' => true,
                            'shaba'              => $bankAccount->shaba,
                            'bank_name'          => $bankAccount->bank_name,
                            'holder_name'        => $bankAccount->account_holder_name,
                        ],
                        type: WalletTransaction::TYPE_WITHDRAW
                    );

                    return success(new WalletTransactionResource($transaction), __('wallet.withdrawal_created'));
                });
            });
        } catch (LockTimeoutException $e) {
            return error(__('global.system_busy_try_again'), [], 429);
        } catch (\Exception $e) {
            return error($e->getMessage(), [], 400);
        }
    }
}
