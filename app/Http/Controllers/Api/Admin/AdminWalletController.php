<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Financial\ProcessTransactionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateTransactionStatusRequest;
use App\Http\Resources\WalletTransactionResource;
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

        $transactions = $query->paginate($request->input('per_page', 20));

        return success(WalletTransactionResource::collection($transactions), __('admin.wallet.list_received'));
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
            $updatedTransaction = $processAction->execute(
                $transaction,
                $request->validated('status'),
                $request->validated('note'),
                Auth::user()
            );

            return success(
                new WalletTransactionResource($updatedTransaction),
                __('admin.wallet.status_updated')
            );
        } catch (\Exception $e) {
            Log::error('Admin Wallet Process Error: '.$e->getMessage());

            return error($e->getMessage(), [], 400);
        }
    }
}
