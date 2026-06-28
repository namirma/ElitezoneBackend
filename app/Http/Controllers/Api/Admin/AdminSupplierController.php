<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSupplierFormulaRequest;
use App\Http\Resources\Order\OrderResource;
use App\Http\Resources\Profile\UserResource;
use App\Models\Order;
use App\Models\SupplierProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * پنل مدیریت — مدیریت سوپلایرها
 *
 * شامل: لیست، جزئیات، آمار سفارشات، بروزرسانی فرمول کمیسیون (+TC / +VAT)
 */
class AdminSupplierController extends Controller
{
    /**
     * لیست سوپلایرها با جستجو و فیلتر وضعیت
     *
     * Query params:
     *   search (optional) — جستجو در موبایل، نام، نام شرکت
     *   is_active (optional, boolean) — فیلتر وضعیت فعال/غیرفعال
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'min:2'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->whereHas('roles', fn ($r) => $r->where('name', 'supplier'))
            ->with(['profile', 'supplier', 'wallet', 'roles'])
            ->when(
                $request->filled('search'),
                fn (Builder $q) => $q->where(
                    fn ($sub) => $sub
                        ->where(User::MOBILE, 'like', "%{$request->search}%")
                        ->orWhereHas('profile', fn ($p) => $p->where('name', 'like', "%{$request->search}%"))
                        ->orWhereHas('supplier', fn ($s) => $s->where(SupplierProfile::COMPANY, 'like', "%{$request->search}%"))
                )
            )
            ->when(
                $request->has('is_active'),
                fn (Builder $q) => $q->where(User::IS_ACTIVE, $request->boolean('is_active'))
            )
            ->latest()
            ->paginate($this->perPage());

        return success(UserResource::collection($users), __('admin.suppliers.list_received'));
    }

    /**
     * جزئیات کامل یک سوپلایر
     *
     * شامل: پروفایل، کیف پول، فلگ‌های فرمول، آمار سفارشات به تفکیک وضعیت
     */
    public function show(User $user): JsonResponse
    {
        abort_unless($user->hasRole('supplier'), 404, __('admin.suppliers.not_found'));

        $user->load(['profile', 'supplier', 'wallet', 'roles', 'badges']);

        // آمار سفارشات این سوپلایر به تفکیک وضعیت
        $orderStats = Order::query()
            ->whereHas('items', fn ($q) => $q->where('supplier_user_id', $user->id))
            ->selectRaw('status, COUNT(*) as count, SUM(final_amount) as total_amount')
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status,
                'count' => (int) $row->count,
                'total_amount' => (float) $row->total_amount,
            ]);

        $formulaFlags = $user->supplier ? [
            'trans_commission' => (bool) $user->supplier->{SupplierProfile::TRANS_COMMISSION},
            'trans_vat' => (bool) $user->supplier->{SupplierProfile::TRANS_VAT},
        ] : null;

        return success([
            'user' => new UserResource($user),
            'formula_flags' => $formulaFlags,
            'order_stats' => $orderStats,
            'wallet' => $user->wallet ? [
                'balance' => (float) $user->wallet->balance,
                'balance_hold' => (float) $user->wallet->balance_hold,
            ] : null,
        ], __('global.received_successfully'));
    }

    /**
     * بروزرسانی فلگ‌های فرمول کمیسیون سوپلایر
     *
     * trans_commission (+TC): آیا کمیسیون روی هزینه حمل اعمال شود؟
     * trans_vat (+VAT): آیا مالیات روی هزینه حمل اعمال شود؟
     *
     * تغییر فقط روی سفارشات جدید اثر می‌گذارد — سفارشات پرداخت‌شده مقادیر
     * snapshot‌شده خودشان را دارند (orders.trans_commission / orders.trans_vat).
     */
    public function updateFormula(UpdateSupplierFormulaRequest $request, User $user): JsonResponse
    {
        abort_unless($user->hasRole('supplier'), 404, __('admin.suppliers.not_found'));

        $supplier = $user->supplier;

        if (! $supplier) {
            return error(__('admin.suppliers.profile_not_found'), [], 404);
        }

        $supplier->update([
            SupplierProfile::TRANS_COMMISSION => $request->boolean('trans_commission'),
            SupplierProfile::TRANS_VAT => $request->boolean('trans_vat'),
        ]);

        return success([
            'trans_commission' => (bool) $supplier->fresh()->{SupplierProfile::TRANS_COMMISSION},
            'trans_vat' => (bool) $supplier->fresh()->{SupplierProfile::TRANS_VAT},
        ], __('admin.suppliers.formula_updated'));
    }

    /**
     * لیست سفارشات مربوط به یک سوپلایر
     */
    public function orders(Request $request, User $user): JsonResponse
    {
        abort_unless($user->hasRole('supplier'), 404, __('admin.suppliers.not_found'));

        $request->validate([
            'status' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = Order::query()
            ->whereHas('items', fn ($q) => $q->where('supplier_user_id', $user->id))
            ->with(Order::resourceRelations())
            ->when(
                $request->filled('status'),
                fn (Builder $q) => $q->where(Order::STATUS, $request->status)
            )
            ->latest()
            ->paginate($this->perPage(10));

        return success(OrderResource::collection($orders), __('global.received_successfully'));
    }
}
