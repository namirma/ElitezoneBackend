<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreSupplierProfileRequest;
use App\Http\Requests\Profile\UpdateSupplierReserveTimeRequest;
use App\Http\Resources\Profile\UserResource;
use App\Http\Resources\Supplier\SupplierCategoryResource;
use App\Http\Resources\Supplier\SupplierProfileResource;
use App\Jobs\Notification\SendSmsJob;
use App\Models\Cart;
use App\Models\Factory;
use App\Models\Order;
use App\Models\SupplierActivityCategory;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Services\Cart\CartParkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SupplierController extends Controller
{
    public function getActivityCategoryId()
    {
        return getSupplierCategoryId();
    }

    public function index()
    {
        $categories = SupplierActivityCategory::with('children')->get();

        return success(SupplierCategoryResource::collection($categories));
    }

    public function getProfile($id): SupplierProfileResource
    {
        $supplier = SupplierProfile::with(['activities' => function ($query) {
            $query->withoutChildren();
        }])->findOrFail($id);

        return new SupplierProfileResource($supplier);
    }

    /**
     * PUT /profile/supplier/reserve-time — اعلامِ اعتبارِ قیمت‌ها (بند ۷.۳).
     *
     * این تنها راهِ «قیمت‌هایم تا این لحظه معتبرند» است، و ثبتش دو اثرِ جانبی
     * دارد که هر دو بخشی از همان اعلام‌اند (بند ۳.۲):
     *   ۱. محصولاتِ این تأمین‌کننده دوباره قابلِ فروش می‌شوند
     *   ۲. سبدهایی که منتظرِ همین اعلام مانده بودند مهلتِ تازه می‌گیرند و
     *      صاحبانشان پیامک می‌گیرند
     *
     * گاردهای «باید در آینده باشد» و «کاهش ممنوع» در FormRequest اعمال می‌شوند.
     */
    public function updateReserveTime(
        UpdateSupplierReserveTimeRequest $request,
        CartParkService $parkService,
    ): JsonResponse {
        $userId = (int) Auth::id();

        $supplier = SupplierProfile::where(SupplierProfile::USER_ID, $userId)->first();

        if (! $supplier) {
            return error(__('profile.supplier_profile_not_found'), [], 404);
        }

        $supplier->update($request->safe()->only([
            SupplierProfile::RESERVE_TIME,
            SupplierProfile::RESERVE_AUTO_EXTEND_MINUTES,
            SupplierProfile::RESERVE_GRACE_HOURS,
        ]));

        // خارج از تراکنش عمداً نیست — این یک به‌روزرسانیِ تکی است و پیامک‌ها
        // صف‌شده‌اند، پس چیزی برای rollback نمی‌ماند.
        $this->notifyWaitingCarts($parkService, $userId);

        return success(
            new SupplierProfileResource($supplier->fresh()),
            'profile.supplier_profile_updated'
        );
    }

    /** سبدهای منتظر را دوباره مهلت‌دار می‌کند و به صاحبانشان خبر می‌دهد (بند ۳.۲). */
    private function notifyWaitingCarts(CartParkService $parkService, int $supplierUserId): void
    {
        $cartIds = $parkService->reparkWaitingCartsFor($supplierUserId);

        if ($cartIds === []) {
            return;
        }

        Cart::query()
            ->with('user')
            ->whereIn(Cart::ID, $cartIds)
            ->get()
            ->each(function (Cart $cart) {
                $mobile = $cart->user?->mobile;

                if ($mobile) {
                    SendSmsJob::dispatch($mobile, __('sms.cart_prices_updated'));
                }
            });
    }

    public function getCategories()
    {
        return success(SupplierCategoryResource::collection(SupplierActivityCategory::getAll()));
    }

    public function store(StoreSupplierProfileRequest $request)
    {
        // Load the existing profile so partial updates don't overwrite unsubmitted fields
        // with null. $this->activity_description etc. were non-existent controller properties
        // that always evaluated to null (same data-loss bug fixed in Employer/EngineerController).
        $existing = SupplierProfile::where(SupplierProfile::USER_ID, Auth::id())->first();

        $supplierProfile = SupplierProfile::updateOrCreate(
            [
                SupplierProfile::USER_ID => Auth::id(),
            ],
            [
                SupplierProfile::USER_ID => Auth::id(),
                SupplierProfile::ACTIVITY_DESCRIPTION => $request->validated('activity_description') ?? $existing?->activity_description,
                SupplierProfile::BRANDS => $request->validated('brands') ?? $existing?->brands,
                SupplierProfile::BRANDS_NOT_RELATED => $request->validated('brands_not_related') ?? $existing?->brands_not_related ?? 1, // default to true
                SupplierProfile::ACTIVITY_HISTORY => $request->validated('activity_history') ?? $existing?->activity_history,
                SupplierProfile::ACTIVITY_PROVINCES => $request->validated('activity_provinces') ?? $existing?->activity_provinces,
                SupplierProfile::PRODUCTION_AMOUNT => $request->validated('production_amount') ?? $existing?->production_amount,
                SupplierProfile::STANDARDS => $request->validated('standards') ?? $existing?->standards,
                SupplierProfile::STANDARDS_NOT_RELATED => $request->validated('standards_not_related') ?? $existing?->standards_not_related ?? 1, // default to true
                SupplierProfile::WEBSITE => $request->validated('website') ?? $existing?->website,
                SupplierProfile::WEBSITE_NOT_RELATED => $request->validated('website_not_related') ?? $existing?->website_not_related ?? 1, // default to true
                SupplierProfile::COMPANY => $request->validated('company') ?? $existing?->company,
            ]
        );
        if ($request->has('activity_category_id')) {
            $supplierProfile->activities()->sync($request->validated('activity_category_id'));
        }

        return success(new UserResource(User::with('supplier')->find(Auth::id())));
    }

    /**
     * انصراف از تأمین‌کنندگی — پروفایل **و** نقش با هم می‌روند.
     *
     * پیش از این فقط ردیف پروفایل حذف می‌شد و نقشِ Laratrust سرِ جایش می‌ماند.
     * نتیجه دقیقاً همان حالتی بود که نباید ممکن باشد: کاربری بدون هیچ ردیفِ
     * `supplier_profiles` که همچنان از `role.supplier` رد می‌شد و کلِ پنل
     * خودسرویس برایش باز بود (`/profile/supplier/dashboard` → ۲۰۰ با
     * `supplier_status: null`). دو مسیرِ دیگر — `AdminSupplierController::reject`
     * و `SyncUserRolesAction` — هر دو نقش را جدا می‌کنند؛ این یکی جا مانده بود.
     *
     * دو گاردِ پیش از حذف، چون «انصراف» فقط برای متقاضی معنا دارد نه برای
     * تأمین‌کننده‌ای که کاتالوگ و سفارشِ زنده دارد:
     *
     * ۱. **کارخانهٔ باقی‌مانده** — `factories.supplier_user_id` به کاربر اشاره
     *    می‌کند و قیمت/موجودی/نرخ حملش همچنان قابل فروش است. با رفتنِ نقش، خودش
     *    هم دیگر نمی‌تواند اصلاحش کند — یعنی کاتالوگی زنده بدون هیچ متولی.
     * ۲. **سفارشِ تمام‌نشده** — فلگ‌های فرمول (`trans_commission`/`trans_vat`)
     *    هنگام **پرداخت** از همین پروفایل خوانده می‌شوند
     *    (`ProcessPreScalePaymentAction`). نبودِ پروفایل یعنی fallback به
     *    `?? true`، پس سفارشی که هنوز پرداخت نشده بی‌صدا با فرمولِ دیگری تسویه
     *    می‌شد.
     */
    public function destroy(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $profile = SupplierProfile::where(SupplierProfile::USER_ID, $user->id)->first();

        if (! $profile) {
            return error(__('profile.supplier_not_found'), [], 404);
        }

        if (Factory::where(Factory::SUPPLIER_USER_ID, $user->id)->exists()) {
            return error(__('profile.supplier_has_factories'), [], 422);
        }

        // همان قاعده‌ی ادمین (`AdminSupplierController::reject`) — یک مرجع.
        //
        // گاردِ قبلی فقط وضعیت را می‌سنجید، پس سفارشِ `delivered` که سند O
        // نگرفته بود (مالیاتش هنوز هولد) یا بدهی معلق داشت از آن رد می‌شد و
        // تأمین‌کننده می‌توانست با پولِ گیرکرده در `balance_hold` برود.
        if (Order::query()->unsettledForSupplier((int) $user->id)->exists()) {
            return error(__('profile.supplier_has_open_orders'), [], 422);
        }

        DB::transaction(function () use ($user, $profile) {
            $profile->activities()->detach();
            $profile->delete();

            // نیمهٔ گم‌شده — بدون این، پروفایل می‌رود و دسترسی می‌ماند.
            $user->removeRole('supplier');
        });

        return success([], __('profile.supplier_deleted'));
    }
}
