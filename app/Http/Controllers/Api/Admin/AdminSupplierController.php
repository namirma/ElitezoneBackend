<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSupplierFormulaRequest;
use App\Http\Resources\Order\OrderResource;
use App\Http\Resources\Profile\UserResource;
use App\Jobs\Notification\SendSmsJob;
use App\Models\Order;
use App\Models\SupplierDocument;
use App\Models\SupplierProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * شامل درخواست‌های در انتظار تأیید هم می‌شود (پروفایل دارند ولی هنوز نقش
     * ندارند) — فیلتر فقط-نقش‌دارها آن‌ها را از دید ادمین پنهان می‌کرد.
     *
     * Query params:
     *   search (optional) — جستجو در موبایل، نام، نام شرکت
     *   is_active (optional, boolean) — فیلتر وضعیت فعال/غیرفعال
     *   status (optional) — فیلتر وضعیت احراز: draft | pending | needs_correction | approved | rejected
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'min:2'],
            'is_active' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'in:draft,pending,needs_correction,approved,rejected'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->where(fn (Builder $q) => $q
                ->whereHas('roles', fn ($r) => $r->where('name', 'supplier'))
                ->orWhereHas('supplier'))
            ->when(
                $request->filled('status'),
                fn (Builder $q) => $q->whereHas(
                    'supplier',
                    fn ($s) => $s->where(SupplierProfile::STATUS, $request->input('status'))
                )
            )
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
        // درخواست‌های pending هنوز نقش ندارند — وجود پروفایل هم کافی است.
        abort_unless(
            $user->hasRole('supplier') || $user->supplier()->exists(),
            404,
            __('admin.suppliers.not_found')
        );

        // مدارک همراهِ پروفایل می‌آیند تا صفِ صلاحیت بتواند «آگهی تأسیس» را ببیند.
        $user->load(['profile', 'supplier.documents', 'wallet', 'roles', 'badges']);

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
            'supplier_status' => $user->supplier?->{SupplierProfile::STATUS},
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

        // TRANS_COMMISSION/TRANS_VAT عمداً fillable نیستند (SupplierProfile) — همان
        // الگوی forceFill که برای STATUS در approve/reject استفاده می‌شود.
        $supplier->forceFill([
            SupplierProfile::TRANS_COMMISSION => $request->boolean('trans_commission'),
            SupplierProfile::TRANS_VAT => $request->boolean('trans_vat'),
        ])->save();

        return success([
            'trans_commission' => (bool) $supplier->fresh()->{SupplierProfile::TRANS_COMMISSION},
            'trans_vat' => (bool) $supplier->fresh()->{SupplierProfile::TRANS_VAT},
        ], __('admin.suppliers.formula_updated'));
    }

    /**
     * تأیید درخواست سوپلایری — تنها مسیر اتصال نقش supplier
     *
     * نقش supplier روی resolve قیمت‌گذاری و فلگ‌های فرمول تسویه اثر دارد،
     * بنابراین خود-انتسابی نیست (SyncUserRolesAction فقط پروفایل pending می‌سازد).
     */
    public function approve(User $user): JsonResponse
    {
        $profile = $user->supplier;

        if (! $profile) {
            return error(__('admin.suppliers.profile_not_found'), [], 404);
        }

        if ($profile->isApproved() && $user->hasRole('supplier')) {
            return error(__('admin.suppliers.already_approved'), [], 409);
        }

        // ⚠️ وضعیت و نقش باید **با هم** بروند یا هیچ‌کدام.
        //
        // این دو نوشتن جدا بودند، و شکستِ بینشان دقیقاً همان قفل‌شدنی را می‌سازد
        // که کامنتِ `reject()` پایین‌تر درباره‌اش هشدار می‌دهد: پروفایل
        // `approved` می‌شود ولی نقش نمی‌چسبد، و `OrderPolicy::manageAsSupplier`
        // که **اول** نقش را می‌سنجد، تأمین‌کننده را از پنلِ خودش بیرون نگه
        // می‌دارد — در حالی که هر گزارشی می‌گوید تأییدشده است.
        //
        // STATUS عمداً fillable نیست — تغییر وضعیت فقط از همین مسیر ادمین مجاز است.
        DB::transaction(function () use ($profile, $user) {
            $profile->forceFill([
                SupplierProfile::STATUS => SupplierProfile::STATUS_APPROVED,
                // دلیلِ ردِ قبلی باید برود، وگرنه پروفایلِ تأییدشده هنوز
                // متنِ «چرا ردت کردیم» را نشان می‌دهد.
                SupplierProfile::REJECTION_REASON => null,
                SupplierProfile::REVIEWED_AT => now(),
            ])->save();

            if (! $user->hasRole('supplier')) {
                $user->addRole('supplier');
            }
        });

        activity()
            ->performedOn($profile)
            ->causedBy(auth()->user())
            ->withProperties(['supplier_user_id' => $user->id])
            ->log('درخواست سوپلایری تأیید شد.');

        // از این لحظه محلِ کارِ تأمین‌کننده پنل است (اسپکِ یکپارچه‌سازی §۴-۳) —
        // پیامک لینکِ پنل را می‌دهد؛ ورود با همان شماره و OTP/رمزِ سایت است.
        SendSmsJob::template($user->{User::MOBILE}, 'supplier_approved', [
            'panel_url' => (string) config('services.supplier_panel.url'),
        ]);

        return success(['supplier_status' => SupplierProfile::STATUS_APPROVED], __('admin.suppliers.approved'));
    }

    /**
     * رد درخواست سوپلایری — وضعیت rejected + جداکردن نقش (اگر متصل بود)
     */
    public function reject(Request $request, User $user): JsonResponse
    {
        // ⚠️ ادمین می‌توانست رد کند ولی هیچ‌جا نمی‌توانست بگوید **چرا**.
        // متقاضی فقط `status: rejected` می‌دید و نمی‌دانست چه چیزی را اصلاح
        // کند — و حالا که ویرایشِ پروفایل او را به صف برمی‌گرداند، این
        // نیمه‌کاره‌بودن بدتر هم هست: راهِ بازگشت باز شده و مقصد معلوم نیست.
        //
        // `min:10` همان قاعده‌ی لغوِ اجباری است: «دلیل» باید جمله باشد نه یک
        // کلمه، وگرنه ثبتش عملاً اختیاری می‌شود.
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'reason.required' => __('admin.suppliers.reject_reason_required'),
            'reason.min' => __('admin.suppliers.reject_reason_too_short'),
        ]);

        $profile = $user->supplier;

        if (! $profile) {
            return error(__('admin.suppliers.profile_not_found'), [], 404);
        }

        // ⚠️ رد کردن تا تسویه‌ی آخرین سفارش ممنوع است (تصمیم مدیریت).
        //
        // این متد نقشِ `supplier` را برمی‌دارد، و `OrderPolicy::manageAsSupplier`
        // پیش از هر چیز `hasRole('supplier')` را می‌سنجد. پس تأمین‌کننده‌ای که
        // وسطِ کار رد شود، روی سفارشِ **پرداخت‌شده‌ی** خودش نه بارنامه می‌تواند
        // ثبت کند، نه سند O بدهد، نه خسارت عودت — سفارش قفل می‌شود و هولد
        // مالیاتش برای همیشه در `balance_hold` می‌ماند. نه خطایی، نه لاگی؛ فقط
        // یک ۴۰۳ در مسیری که دیگر کسی صدایش نمی‌زند.
        $unsettled = Order::query()->unsettledForSupplier((int) $user->id)->count();

        if ($unsettled > 0) {
            return error(
                __('admin.suppliers.reject_blocked_by_orders', ['count' => $unsettled]),
                [],
                422
            );
        }

        // قرینه‌ی `approve()` — وضعیت و نقش با هم، وگرنه نقشِ باقی‌مانده روی
        // پروفایلِ ردشده یعنی کسی که رد شده هنوز به پنل دسترسی دارد.
        DB::transaction(function () use ($profile, $user, $data) {
            $profile->forceFill([
                SupplierProfile::STATUS => SupplierProfile::STATUS_REJECTED,
                SupplierProfile::REJECTION_REASON => $data['reason'],
                SupplierProfile::REVIEWED_AT => now(),
            ])->save();

            if ($user->hasRole('supplier')) {
                $user->removeRole('supplier');
            }
        });

        activity()
            ->performedOn($profile)
            ->causedBy(auth()->user())
            ->withProperties(['supplier_user_id' => $user->id, 'reason' => $data['reason']])
            ->log('درخواست سوپلایری رد شد.');

        SendSmsJob::template($user->{User::MOBILE}, 'supplier_rejected', ['reason' => $data['reason']]);

        return success(['supplier_status' => SupplierProfile::STATUS_REJECTED], __('admin.suppliers.rejected'));
    }

    /**
     * «نیاز به اصلاح» — حالتِ سومِ صفِ صلاحیت
     *
     * برای وقتی که درخواست ناقص است ولی رد کردنش زیاده‌روی است (مدرکِ ناخوانا،
     * کدپستیِ غلط). نقش وصل نمی‌شود، متقاضی دلیل را در پروفایلِ سایت می‌بیند و
     * با ارسالِ دوباره (`SupplierController::store`) به `pending` برمی‌گردد.
     * روی تأمین‌کننده‌ی تأییدشده معنا ندارد — آن‌جا مسیر `reject` است.
     */
    public function requestCorrection(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'reason.required' => __('admin.suppliers.correction_reason_required'),
            'reason.min' => __('admin.suppliers.correction_reason_too_short'),
        ]);

        $profile = SupplierProfile::query()->where(SupplierProfile::USER_ID, $user->id)->first();

        if (! $profile) {
            return error(__('admin.suppliers.profile_not_found'), [], 404);
        }

        if ($profile->isApproved() || $user->hasRole('supplier')) {
            return error(__('admin.suppliers.cannot_correct_approved'), [], 409);
        }

        $profile->forceFill([
            SupplierProfile::STATUS => SupplierProfile::STATUS_NEEDS_CORRECTION,
            SupplierProfile::REJECTION_REASON => $data['reason'],
            SupplierProfile::REVIEWED_AT => now(),
        ])->save();

        activity()
            ->performedOn($profile)
            ->causedBy(auth()->user())
            ->withProperties(['supplier_user_id' => $user->id, 'reason' => $data['reason']])
            ->log('برای درخواست سوپلایری اصلاح خواسته شد.');

        SendSmsJob::template($user->{User::MOBILE}, 'supplier_needs_correction', ['reason' => $data['reason']]);

        return success(['supplier_status' => SupplierProfile::STATUS_NEEDS_CORRECTION], __('admin.suppliers.correction_requested'));
    }

    /**
     * دانلودِ یک مدرکِ متقاضی از دیسکِ خصوصی
     *
     * همان فایلی که `SupplierDocumentController::download` به صاحبش می‌دهد؛ این
     * مسیر زیرِ `manage-users` است تا ادمینِ صفِ صلاحیت لازم نباشد از مسیرِ
     * پروفایلِ کاربر رد شود. مدرک باید مالِ همین کاربر باشد — نه هر شناسه‌ای.
     */
    public function downloadDocument(User $user, SupplierDocument $document): StreamedResponse
    {
        $profile = $user->supplier;

        if (! $profile || (int) $document->{SupplierDocument::SUPPLIER_PROFILE_ID} !== (int) $profile->getKey()) {
            throw new BusinessException(__('admin.suppliers.document_not_found'), 404);
        }

        $path = $document->{SupplierDocument::PATH};

        if (! Storage::disk(SupplierDocument::DISK)->exists($path)) {
            throw new BusinessException(__('supplier.documents.file_missing'), 404);
        }

        return Storage::disk(SupplierDocument::DISK)->download($path, $document->{SupplierDocument::ORIGINAL_NAME});
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
