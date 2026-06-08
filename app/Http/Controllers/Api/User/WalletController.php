<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Financial\AttachReceiptToDepositAction;
use App\Actions\Financial\DeleteReceiptAction;
use App\Actions\Financial\FundWalletAction;
use App\Actions\Financial\RequestWithdrawalAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\StoreDepositReceiptRequest;
use App\Http\Requests\Financial\WalletDepositRequest;
use App\Http\Requests\Financial\WalletWithdrawalRequest;
use App\Http\Resources\Financial\WalletResource;
use App\Http\Resources\Financial\WalletTransactionResource;
use App\Models\WalletTransaction;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WalletController extends Controller
{
    public function __construct(
        protected FundWalletAction              $fundAction,
        protected RequestWithdrawalAction       $withdrawalAction,
        protected AttachReceiptToDepositAction  $attachReceiptAction,
        protected DeleteReceiptAction           $deleteReceiptAction,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Balance & transactions
    // ─────────────────────────────────────────────────────────────────────────

    public function getWallet(): JsonResponse
    {
        $wallet = Auth::user()->wallet()->firstOrCreate([]);

        return success(new WalletResource($wallet), __('wallet.balance_received'));
    }

    public function getTransactions(Request $request): JsonResponse
    {
        $wallet = Auth::user()->wallet()->firstOrCreate([]);

        $transactions = $this->applyTransactionFilters($wallet->transactions()->latest(), $request)
            ->paginate($this->perPage(10));

        return success(
            WalletTransactionResource::collection($transactions)->response()->getData(true),
            __('wallet.transactions_received'),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Deposit (gateway / bank transfer)
    // ─────────────────────────────────────────────────────────────────────────

    public function requestDeposit(WalletDepositRequest $request): JsonResponse
    {
        $data = $request->validated();

        $transaction = $this->fundAction->execute(
            user:          Auth::user(),
            amount:        $data['amount'],
            paymentMethod: $data['payment_method'],
            description:   __('wallet.deposit_description'),
        );

        // Bank transfer — user will upload receipt later
        if ($data['payment_method'] === WalletTransaction::METHOD_BANK_TRANSFER) {
            return success([
                'transaction_id' => $transaction->id,
                'type'           => 'manual_payment',
                'message'        => __('wallet.deposit_manual_info', ['amount' => number_format($data['amount'])]),
            ], __('wallet.deposit_created'));
        }

        // Online gateway redirect
        return success([
            'transaction_id' => $transaction->id,
            'type'           => 'gateway_redirect',
            'redirect_url'   => "https://dummy-gateway.com/pay/{$transaction->id}",
        ], __('wallet.redirect_to_gateway'));
    }

    public function storeReceipt(StoreDepositReceiptRequest $request): JsonResponse
    {
        $data = $request->validated();

        $description = __('wallet.receipt_description', [
            'number' => $data['receipt_number'],
            'bank'   => $data['bank_name'],
        ]).(! empty($data['description']) ? ' - '.$data['description'] : '');

        $transaction = $this->fundAction->execute(
            user:          Auth::user(),
            amount:        $data['amount'],
            paymentMethod: WalletTransaction::METHOD_BANK_TRANSFER,
            description:   $description,
            receiptFile:   $request->file('receipt_image'),
            extraMeta:     ['receipt_info' => [
                'receipt_number' => $data['receipt_number'],
                'bank_name'      => $data['bank_name'],
                'payment_date'   => $data['payment_date'],
            ]],
        );

        return success(new WalletTransactionResource($transaction), __('wallet.receipt_created'));
    }

    public function uploadDepositReceipt(Request $request, WalletTransaction $transaction): JsonResponse
    {
        $request->validate(['receipt' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120']);

        try {
            $updated = $this->attachReceiptAction->execute($transaction, Auth::id(), $request->file('receipt'));
        } catch (RuntimeException $e) {
            return error($e->getMessage(), [], $e->getCode() ?: 400);
        }

        return success(new WalletTransactionResource($updated), __('wallet.receipt_uploaded'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Withdrawal
    // ─────────────────────────────────────────────────────────────────────────

    public function requestWithdrawal(WalletWithdrawalRequest $request): JsonResponse
    {
        try {
            $transaction = $this->withdrawalAction->execute(
                Auth::user(),
                (float) $request->validated('amount'),
                (int)   $request->validated('user_bank_account_id'),
            );

            return success(new WalletTransactionResource($transaction), __('wallet.withdrawal_created'));
        } catch (LockTimeoutException) {
            return error(__('global.system_busy_try_again'), [], 429);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return error(__('wallet.bank_account_not_found'), [], 404);
        } catch (\Exception $e) {
            return error($e->getMessage(), [], 400);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Receipt management
    // ─────────────────────────────────────────────────────────────────────────

    public function getReceipts(): JsonResponse
    {
        $wallet = Auth::user()->wallet;
        if (! $wallet) {
            return success([], __('wallet.receipts_list'));
        }

        $receipts = $wallet->transactions()
            ->where('type', WalletTransaction::TYPE_DEPOSIT)
            ->whereJsonContains('meta->payment_method', WalletTransaction::METHOD_BANK_TRANSFER)
            ->latest()
            ->paginate($this->perPage());

        return success(WalletTransactionResource::collection($receipts), __('wallet.receipts_list'));
    }

    public function downloadReceipt(WalletTransaction $transaction): StreamedResponse|JsonResponse
    {
        if ($transaction->wallet->user_id !== Auth::id()) {
            return error(__('auth.forbidden'), [], 403);
        }

        $meta = $transaction->meta ?? [];
        $path = $meta['receipt_path'] ?? ($meta['receipt_info']['image_path'] ?? null);

        if (! $path || ! Storage::disk('public')->exists($path)) {
            return error(__('wallet.receipt_file_not_found'), [], 404);
        }

        $downloadName = $meta['receipt_info']['original_name'] ?? basename($path);

        return Storage::disk('public')->download($path, $downloadName);
    }

    public function deleteReceipt(WalletTransaction $transaction): JsonResponse
    {
        try {
            $this->deleteReceiptAction->execute($transaction, Auth::id());
        } catch (RuntimeException $e) {
            return error($e->getMessage(), [], $e->getCode() ?: 400);
        }

        return success([], __('wallet.receipt_deleted'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Apply type / status / range filters to a transactions query.
     * Kept as a private helper because it is local to this controller's
     * GET /wallet/transactions endpoint.
     */
    private function applyTransactionFilters($query, Request $request)
    {
        return $query
            ->when($request->filled('type'), function ($q) use ($request) {
                match ($request->input('type')) {
                    'deposit'  => $q->where('type', WalletTransaction::TYPE_DEPOSIT),
                    'withdraw' => $q->where('type', WalletTransaction::TYPE_WITHDRAW),
                    default    => null,
                };
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('range'), function ($q) use ($request) {
                $now = now();
                match ($request->input('range')) {
                    '1_month'  => $q->where('created_at', '>=', $now->subMonth()),
                    '3_months' => $q->where('created_at', '>=', $now->subMonths(3)),
                    '1_year'   => $q->where('created_at', '>=', $now->subYear()),
                    default    => null,
                };
            });
    }
}
