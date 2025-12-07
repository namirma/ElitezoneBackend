<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateTransactionStatusRequest;
use App\Http\Resources\WalletTransactionResource;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\User;
use App\Jobs\SendSmsJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminWalletController extends Controller
{
    /**
     * لیست کلی تراکنش‌ها برای ادمین (با فیلترهای پیشرفته)
     */
    public function index(Request $request): JsonResponse
    {
        $query = WalletTransaction::with(['wallet.user.profile', 'wallet.user'])
            ->latest();

        // فیلتر بر اساس وضعیت 
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // فیلتر بر اساس نوع (فیش واریزی یا درخواست برداشت)
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // فیلتر بر اساس کاربر خاص
        if ($request->filled('user_id')) {
            $query->whereHas('wallet', fn($q) => $q->where('user_id', $request->user_id));
        }

        // فیلتر بر اساس متد پرداخت (مثلاً فقط فیش‌ها)
        if ($request->filled('payment_method')) {
            $query->where('meta->payment_method', $request->payment_method);
        }

        $transactions = $query->paginate($request->input('per_page', 20));

        return success(WalletTransactionResource::collection($transactions), __('admin.wallet.list_received'));
    }

    /**
     * تغییر وضعیت تراکنش (تایید یا رد فیش/برداشت)
     */
    public function changeStatus(UpdateTransactionStatusRequest $request, WalletTransaction $transaction): JsonResponse
    {
        $admin = Auth::user();
        $newStatus = $request->validated('status');
        $note = $request->validated('note');

        // فقط تراکنش‌های در انتظار را می‌توان تعیین تکلیف کرد
        if ($transaction->status !== WalletTransaction::STATUS_PENDING) {
            return error(__('admin.wallet.already_processed'), [], 400);
        }

        try {
            DB::transaction(function () use ($transaction, $newStatus, $note, $admin) {

                $wallet = Wallet::where('id', $transaction->wallet_id)->lockForUpdate()->first();

                // --- سناریوی ۱: تایید/رد واریز (Deposit) ---
                if ($transaction->type === WalletTransaction::TYPE_DEPOSIT) {
                    if ($newStatus === WalletTransaction::STATUS_CONFIRMED) {
                        $wallet->increment('balance', $transaction->amount);
                    }
                }

                // --- سناریوی ۲: تایید/رد برداشت (Withdraw) ---
                elseif ($transaction->type === WalletTransaction::TYPE_WITHDRAW) {
                    if ($newStatus === WalletTransaction::STATUS_REJECTED) {
                        $wallet->increment('balance', $transaction->amount);
                    }
                }

                // آپدیت تراکنش
                $transaction->update([
                    'status' => $newStatus,
                    'description' => $transaction->description . ($note ? " | توضیحات ادمین: {$note}" : ''),
                ]);

                // ثبت لاگ مدیریتی
                activity()
                    ->performedOn($transaction)
                    ->causedBy($admin)
                    ->withProperties([
                        'old_status' => 'pending',
                        'new_status' => $newStatus,
                        'amount'     => $transaction->amount,
                        'note'       => $note
                    ])
                    ->log("وضعیت تراکنش توسط ادمین به {$newStatus} تغییر یافت.");
            });

            // ارسال پیامک اطلاع‌رسانی به کاربر
            $this->notifyUser($transaction, $newStatus, $note);

            return success(new WalletTransactionResource($transaction->fresh()), __('admin.wallet.status_updated'));

        } catch (\Exception $e) {
            Log::error("Admin Wallet Process Error: " . $e->getMessage());
            return error(__('global.operation_failed'), ['debug' => $e->getMessage()], 500);
        }
    }

    /**
     * ارسال پیامک وضعیت جدید به کاربر
     */
    private function notifyUser(WalletTransaction $transaction, string $status, ?string $note): void
    {
        $user = $transaction->wallet->user;

        if (!$user) return;

        $amount = number_format($transaction->amount);
        $typeFa = $transaction->type === WalletTransaction::TYPE_DEPOSIT ? 'واریز' : 'برداشت';

        if ($status === WalletTransaction::STATUS_CONFIRMED) {
            $msg = __('sms.transaction_confirmed', ['type' => $typeFa, 'amount' => $amount]);
        } else {
            $msg = __('sms.transaction_rejected', ['type' => $typeFa, 'amount' => $amount]);
            if ($note) $msg .= "\nدلیل: $note";
        }

        SendSmsJob::dispatch($user->mobile, $msg);
    }
}
