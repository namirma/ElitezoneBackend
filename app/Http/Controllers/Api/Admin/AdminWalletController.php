<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Financial\ProcessTransactionAction;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateTransactionStatusRequest;
use App\Http\Resources\Financial\WalletTransactionResource;
use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdminWalletController extends Controller
{
    /**
     * لیست کلی تراکنش‌ها برای ادمین (با فیلترهای پیشرفته)
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string', 'max:30'],
            'type' => ['nullable', 'string', 'max:30'],
            'user_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = WalletTransaction::with(['wallet.user.profile', 'wallet.user'])
            ->latest();

        // فیلترها (استفاده از نام‌های استاندارد دیتابیس)
        if ($request->filled('status')) {
            $query->where(WalletTransaction::STATUS, $request->status);
        }

        if ($request->filled('type')) {
            $query->where(WalletTransaction::TYPE, $request->type);
        }

        if ($request->filled('user_id')) {
            $query->whereHas('wallet', fn ($q) => $q->where('user_id', $request->user_id));
        }

        $transactions = $query->paginate($this->perPage());

        return success(WalletTransactionResource::collection($transactions), __('admin.wallet.list_received'));
    }

    /**
     * جزئیات یک تراکنش برای صفحه‌ی اختصاصیِ بک‌آفیس (تأیید/رد از همان‌جا).
     */
    public function show(WalletTransaction $transaction): JsonResponse
    {
        $transaction->load(['wallet.user.profile', 'order']);

        return success(new WalletTransactionResource($transaction), __('global.received_successfully'));
    }

    /**
     * تغییر وضعیت تراکنش (تایید یا رد فیش/برداشت)
     */
    public function changeStatus(
        UpdateTransactionStatusRequest $request,
        WalletTransaction $transaction,
        ProcessTransactionAction $processAction
    ): JsonResponse {
        try {
            // اجرای اکشن پردازش
            $verifiedAmount = $request->validated('verified_amount');
            $updatedTransaction = $processAction->execute(
                $transaction,
                $request->validated('status'),
                $request->validated('note'),
                Auth::user(),
                $verifiedAmount !== null ? (float) $verifiedAmount : null
            );

            return success(
                new WalletTransactionResource($updatedTransaction),
                __('admin.wallet.status_updated')
            );
        } catch (BusinessException $e) {
            // تسویه‌ی بانکیِ برداشت در جریان است، یا واریز پس از ردِ آن انجام شده.
            return error($e->getMessage(), [], 409);
        } catch (\Exception $e) {
            Log::error('Admin wallet process failed', [
                'transaction_id' => $transaction->id,
                'admin_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return error(__('admin.wallet.processing_failed'), [], 500);
        }
    }
}
