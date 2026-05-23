<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;

use App\Actions\Financial\DebitWalletAction;
use App\Actions\Financial\FundWalletAction;
use App\Actions\Financial\RequestWithdrawalAction;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

class WalletController extends Controller
{
    public function __construct(
        protected DebitWalletAction $debitAction,
        protected FundWalletAction $fundAction,
        protected RequestWithdrawalAction $withdrawalAction,
    ) {}

    // =========================================================================
    //  بخش ۱: اطلاعات و تراکنش‌ها
    // =========================================================================

    public function getWallet(): JsonResponse
    {
        $wallet = Auth::user()->wallet()->firstOrCreate([]);

        return success(new WalletResource($wallet), __('wallet.balance_received'));
    }

    public function getTransactions(Request $request): JsonResponse
    {
        $wallet = Auth::user()->wallet()->firstOrCreate([]);
        $query = $wallet->transactions()->latest();

        // فیلترها
        if ($request->filled('type')) {
            $type = $request->input('type');
            if ($type === 'deposit') {
                $query->where('type', WalletTransaction::TYPE_DEPOSIT);
            } elseif ($type === 'withdraw') {
                $query->where('type', WalletTransaction::TYPE_WITHDRAW);
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('range')) {
            $now = now();
            match ($request->input('range')) {
                '1_month' => $query->where('created_at', '>=', $now->subMonth()),
                '3_months' => $query->where('created_at', '>=', $now->subMonths(3)),
                '1_year' => $query->where('created_at', '>=', $now->subYear()),
                default => null,
            };
        }

        $transactions = $query->paginate($request->input('per_page', 10));

        return success(
            WalletTransactionResource::collection($transactions)->response()->getData(true),
            __('wallet.transactions_received')
        );
    }

    // =========================================================================
    //  بخش ۲: شارژ (درگاه) و واریز (فیش)
    // =========================================================================

    /**
     * درخواست شارژ (معمولاً برای درگاه آنلاین یا اعلام واریز کارت به کارت بدون آپلود فایل در لحظه اول)
     */
    public function requestDeposit(WalletDepositRequest $request): JsonResponse
    {
        $data = $request->validated();

        $transaction = $this->fundAction->execute(
            user: Auth::user(),
            amount: $data['amount'],
            paymentMethod: $data['payment_method'],
            description: __('wallet.deposit_description')
        );

        // سناریو ۱: کارت به کارت (کاربر بعداً فیش آپلود می‌کند)
        if ($data['payment_method'] === WalletTransaction::METHOD_BANK_TRANSFER) {
            return success([
                'transaction_id' => $transaction->id,
                'type' => 'manual_payment',
                'message' => __('wallet.deposit_manual_info', ['amount' => number_format($data['amount'])]),
            ], __('wallet.deposit_created'));
        }

        // سناریو ۲: درگاه آنلاین
        return success([
            'transaction_id' => $transaction->id,
            'type' => 'gateway_redirect',
            'redirect_url' => "https://dummy-gateway.com/pay/{$transaction->id}", // لینک درگاه خود را اینجا بگذارید
        ], __('wallet.redirect_to_gateway'));
    }

    /**
     * ثبت فیش بانکی (آپلود مستقیم فایل و ثبت تراکنش)
     */
    public function storeReceipt(StoreDepositReceiptRequest $request): JsonResponse
    {
        $data = $request->validated();

        // ساخت توضیحات
        $desc = __('wallet.receipt_description', [
            'number' => $data['receipt_number'],
            'bank' => $data['bank_name'],
        ]).(! empty($data['description']) ? ' - '.$data['description'] : '');

        // آماده‌سازی دیتای فیش برای ذخیره در متا
        $receiptInfo = [
            'receipt_number' => $data['receipt_number'],
            'bank_name' => $data['bank_name'],
            'payment_date' => $data['payment_date'],
        ];

        // استفاده از اکشن برای انجام تمام مراحل
        $transaction = $this->fundAction->execute(
            user: Auth::user(),
            amount: $data['amount'],
            paymentMethod: WalletTransaction::METHOD_BANK_TRANSFER,
            description: $desc,
            receiptFile: $request->file('receipt_image'), // فایل را پاس می‌دهیم
            extraMeta: ['receipt_info' => $receiptInfo]
        );

        return success(new WalletTransactionResource($transaction), __('wallet.receipt_created'));
    }

    /**
     * آپلود فیش برای تراکنشی که قبلا (در requestDeposit) ایجاد شده است
     */
    public function uploadDepositReceipt(Request $request, WalletTransaction $transaction): JsonResponse
    {
        // چک کردن مالکیت
        if ($transaction->wallet->user_id !== Auth::id()) {
            return error(__('auth.forbidden'), [], 403);
        }

        // چک کردن وضعیت
        $isBankTransfer = ($transaction->meta['payment_method'] ?? '') === WalletTransaction::METHOD_BANK_TRANSFER;
        if ($transaction->type !== WalletTransaction::TYPE_DEPOSIT || $transaction->status !== WalletTransaction::STATUS_PENDING || ! $isBankTransfer) {
            return error(__('wallet.invalid_upload_transaction'), [], 400);
        }

        $request->validate(['receipt' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120']);

        // چون اینجا فقط آپدیت است و اکشن create داریم، دستی آپدیت می‌کنیم
        // یا می‌توانیم یک متد updateReceipt در اکشن بسازیم. فعلاً اینجا ساده نگه می‌داریم:
        $file = $request->file('receipt');
        $originalName = $file->getClientOriginalName();
        $path = $file->store(WalletTransaction::PATH_RECEIPTS, 'public');

        $meta = $transaction->meta ?? [];
        $meta['receipt_path'] = $path;
        $meta['receipt_info']['image_path'] = $path;
        $meta['receipt_info']['original_name'] = $originalName; // ذخیره نام اصلی

        $transaction->update([
            'description' => $transaction->description.' | رسید آپلود شد',
            'meta' => $meta,
        ]);

        return success(new WalletTransactionResource($transaction), __('wallet.receipt_uploaded'));
    }

    // =========================================================================
    //  بخش ۳: برداشت و دانلود و لیست
    // =========================================================================

    public function requestWithdrawal(WalletWithdrawalRequest $request): JsonResponse
    {
        $user = Auth::user();
        $amount = (float) $request->validated('amount');
        $bankId = (int) $request->validated('user_bank_account_id');

        try {
            $transaction = $this->withdrawalAction->execute($user, $amount, $bankId);

            return success(new WalletTransactionResource($transaction), __('wallet.withdrawal_created'));
        } catch (LockTimeoutException) {
            return error(__('global.system_busy_try_again'), [], 429);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return error(__('wallet.bank_account_not_found'), [], 404);
        } catch (\Exception $e) {
            return error($e->getMessage(), [], 400);
        }
    }

    public function downloadReceipt(WalletTransaction $transaction): StreamedResponse|JsonResponse
    {
        if ($transaction->wallet->user_id !== Auth::id()) {
            return error(__('auth.forbidden'), [], 403);
        }

        $meta = $transaction->meta ?? [];
        $path = $meta['receipt_path'] ?? ($meta['receipt_info']['image_path'] ?? null);

        if (! $path || ! Storage::disk('public')->exists($path)) {
            return error('فایل رسید یافت نشد.', [], 404);
        }

        $downloadName = $meta['receipt_info']['original_name'] ?? basename($path);

        return Storage::disk('public')->download($path, $downloadName);
    }

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
}
