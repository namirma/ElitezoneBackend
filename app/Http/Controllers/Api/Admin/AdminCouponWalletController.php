<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Constants\FinancialCodes;
use App\Http\Controllers\Controller;
use App\Http\Resources\Financial\WalletTransactionResource;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Financial\CouponWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «کیف پول کوپن‌های تخفیف» در بک‌آفیس — مدیریت، دورِ پنجم (۱۴۰۵/۰۷/۱۴): «مدیریت و دسترسی به
 * کیف پولِ کوپن‌های تخفیف توسطِ شرکت در بک‌آفیس انجام می‌شود.» موجودی، دفتر، شارژ از کیف پولِ
 * سازه و برگشت به آن. هر جابه‌جایی ردِ ممیزی دارد (`CouponWallet::move`).
 */
class AdminCouponWalletController extends Controller
{
    public function __construct(private readonly CouponWallet $wallet) {}

    // GET /admin/coupon-wallet
    public function show(): JsonResponse
    {
        $userId = $this->wallet->userId();
        $walletId = $userId !== null ? Wallet::where(Wallet::USER_ID, $userId)->value('id') : null;

        $ledger = $walletId === null
            ? collect()
            : WalletTransaction::where('wallet_id', $walletId)->latest('id')->limit(100)->get();

        return success([
            'code' => FinancialCodes::WALLET_COUPON,
            'configured' => $userId !== null,
            'balance' => (string) $this->wallet->balance(),
            'transactions' => WalletTransactionResource::collection($ledger),
        ], __('coupons.coupon_wallet_received'));
    }

    // POST /admin/coupon-wallet/fund — از کیف پولِ سازه به کیف پولِ کوپن
    public function fund(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return success($this->wallet->fund($request->user(), (float) $data['amount'], $data['note']), __('coupons.coupon_wallet_funded'));
    }

    // POST /admin/coupon-wallet/defund — برگشتِ مانده به کیف پولِ سازه
    public function defund(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return success($this->wallet->defund($request->user(), (float) $data['amount'], $data['note']), __('coupons.coupon_wallet_funded'));
    }
}
