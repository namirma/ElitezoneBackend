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
use App\Models\SupplierDocument;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Services\Cart\CartParkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SupplierController extends Controller
{
    /**
     * دسته‌های فعالیتِ **خودِ کاربر جاری**.
     *
     * ⚠️ پیش از این `getSupplierCategoryId()` را صدا می‌زد، که یک
     * `DB::table('supplier_activities')->pluck('activity_category_id')` بدونِ
     * هیچ شرطی بود — یعنی **کلِ جدولِ واسطِ همه‌ی تأمین‌کننده‌ها** به هر کاربرِ
     * احرازشده‌ای برمی‌گشت، حتی کسی که اصلاً تأمین‌کننده نیست. با پارامترِ
     * `activity_category_id` هم فقط همان مقدار را بازتاب می‌داد، پس عملاً یک
     * «آیا کسی این دسته را دارد؟» عمومی بود.
     *
     * نامِ متد از ابتدا «دسته‌های من» را وعده می‌داد؛ حالا همان را می‌دهد.
     * پاسخ هم مثل هر اندپوینتِ دیگری داخلِ پاکتِ `success()` است — قبلاً یک
     * Collectionِ خام بیرون از قرارداد برمی‌گشت.
     */
    public function getActivityCategoryId(): JsonResponse
    {
        // ‏`supplier_activities.supplier_id` به `supplier_profiles.id` اشاره
        // می‌کند نه به `users.id` — پس شناسه‌ی پروفایل لازم است، نه شناسه‌ی کاربر.
        $profileId = SupplierProfile::where(SupplierProfile::USER_ID, Auth::id())->value('id');

        $ids = $profileId === null
            ? collect()
            : DB::table('supplier_activities')
                ->where('supplier_id', $profileId)
                ->pluck('activity_category_id');

        return success($ids);
    }

    // `index()` حذف شد: هیچ روتی به آن اشاره نمی‌کرد و کارش هم تکراری بود —
    // `getCategories()` (روت `/profile/supplier/activity-categories`) همان
    // دسته‌بندی‌ها را از `SupplierActivityCategory::getAll()` برمی‌گرداند.

    /**
     * پروفایل سوپلایریِ **خودِ کاربر جاری** — شامل `status`.
     *
     * چرا لازم است: نقشِ `supplier` فقط با تأییدِ ادمین وصل می‌شود، و
     * `ProfileController` رابطه‌ی سوپلایر را هم فقط بر همان اساس لود می‌کند. نتیجه
     * این بود که دقیقاً کسانی که باید وضعیتشان را ببینند — متقاضیِ **در انتظار**
     * و کاربرِ **ردشده** (که نقشش جدا می‌شود) — هیچ راهی برای دیدنش نداشتند.
     *
     * «هنوز درخواست نداده‌ام» یک حالتِ عادی است نه خطا، پس پاسخ ۲۰۰ با
     * `data: null` است؛ پنل نباید مجبور شود یک ۴۰۴ را به‌عنوان وضعیتِ معمولی
     * هندل کند.
     *
     * scope همیشه روی `Auth::id()` قفل است — این متد هرگز شناسه از ورودی نمی‌گیرد.
     */
    public function show(): JsonResponse
    {
        $supplier = SupplierProfile::with(['activities' => fn ($query) => $query->withoutChildren()])
            ->where(SupplierProfile::USER_ID, Auth::id())
            ->first();

        return success(
            $supplier ? new SupplierProfileResource($supplier) : null,
            'profile.supplier_profile_received'
        );
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
        //
        // ⚠️ فقط وقتی واقعاً چیزی عوض شده. `notifyWaitingCarts()` به **همه‌ی**
        // سبدهای پارک‌شده‌ی این تأمین‌کننده پیامک می‌دهد و مهلتشان را هم تمدید
        // می‌کند. `UpdateSupplierReserveTimeRequest` فقط *کاهش* را رد می‌کند، پس
        // ارسالِ دوباره‌ی همان مقدار اعتبارسنجی را رد می‌کرد و بی‌سروصدا یک موجِ
        // پیامکِ دیگر به همان خریدارها می‌فرستاد — کافی بود فرم دو بار ذخیره شود.
        if ($supplier->wasChanged()) {
            $this->notifyWaitingCarts($parkService, $userId);
        }

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
                    SendSmsJob::template($mobile, 'cart_prices_updated');
                }
            });
    }

    public function getCategories()
    {
        return success(SupplierCategoryResource::collection(SupplierActivityCategory::getAll()));
    }

    /**
     * ثبت / ویرایشِ درخواستِ احرازِ صلاحیت.
     *
     * یک بدنه، دو معنا (§۴-۱ اسپکِ یکپارچه‌سازی): برای پروفایلِ `draft` /
     * `rejected` / `needs_correction` یعنی **ارسالِ درخواست** (→ `pending`)؛
     * برای `approved` یعنی ویرایشِ مشخصاتِ شرکت از پنل، بدونِ ازدست‌دادنِ تأیید.
     * شخصِ حقوقی بدونِ «آگهی تأسیس» ارسال نمی‌شود — مدارک را قبل از این فرم
     * می‌فرستند (`SupplierDocumentController`، بیرونِ `role.supplier`).
     */
    public function store(StoreSupplierProfileRequest $request)
    {
        // Load the existing profile so partial updates don't overwrite unsubmitted fields
        // with null. $this->activity_description etc. were non-existent controller properties
        // that always evaluated to null (same data-loss bug fixed in Employer/EngineerController).
        $existing = SupplierProfile::where(SupplierProfile::USER_ID, Auth::id())->first();

        $submitting = $existing === null || $existing->isSubmittable();
        $entityType = $request->validated(SupplierProfile::ENTITY_TYPE)
            ?? $existing->{SupplierProfile::ENTITY_TYPE}
            ?? SupplierProfile::ENTITY_COMPANY;

        if ($submitting && $entityType === SupplierProfile::ENTITY_COMPANY
            && ! ($existing?->documents()->where(SupplierDocument::KIND, SupplierDocument::KIND_GAZETTE)->exists() ?? false)) {
            return error(__('supplier.qualification.gazette_required'), [], 422);
        }

        $optional = fn (string $key) => $request->validated($key) ?? $existing?->{$key};

        $supplierProfile = SupplierProfile::updateOrCreate(
            [
                SupplierProfile::USER_ID => Auth::id(),
            ],
            [
                SupplierProfile::USER_ID => Auth::id(),
                SupplierProfile::ACTIVITY_DESCRIPTION => $optional(SupplierProfile::ACTIVITY_DESCRIPTION),
                SupplierProfile::BRANDS => $optional(SupplierProfile::BRANDS),
                SupplierProfile::BRANDS_NOT_RELATED => $request->validated('brands_not_related') ?? $existing?->brands_not_related ?? 1, // default to true
                SupplierProfile::ACTIVITY_HISTORY => $optional(SupplierProfile::ACTIVITY_HISTORY),
                SupplierProfile::ACTIVITY_PROVINCES => $optional(SupplierProfile::ACTIVITY_PROVINCES),
                SupplierProfile::PRODUCTION_AMOUNT => $optional(SupplierProfile::PRODUCTION_AMOUNT),
                SupplierProfile::STANDARDS => $optional(SupplierProfile::STANDARDS),
                SupplierProfile::STANDARDS_NOT_RELATED => $request->validated('standards_not_related') ?? $existing?->standards_not_related ?? 1, // default to true
                SupplierProfile::WEBSITE => $optional(SupplierProfile::WEBSITE),
                SupplierProfile::WEBSITE_NOT_RELATED => $request->validated('website_not_related') ?? $existing?->website_not_related ?? 1, // default to true
                SupplierProfile::COMPANY => $request->validated(SupplierProfile::COMPANY),
                SupplierProfile::ENTITY_TYPE => $entityType,
                SupplierProfile::NATIONAL_ID => $request->validated(SupplierProfile::NATIONAL_ID),
                SupplierProfile::REGISTRATION_NUMBER => $request->validated(SupplierProfile::REGISTRATION_NUMBER),
                SupplierProfile::ECONOMIC_CODE => $request->validated(SupplierProfile::ECONOMIC_CODE),
                SupplierProfile::REPRESENTATIVE_NAME => $request->validated(SupplierProfile::REPRESENTATIVE_NAME),
                SupplierProfile::REPRESENTATIVE_NATIONAL_CODE => $request->validated(SupplierProfile::REPRESENTATIVE_NATIONAL_CODE),
                SupplierProfile::LANDLINE => $request->validated(SupplierProfile::LANDLINE),
                SupplierProfile::PROVINCE_ID => $request->validated(SupplierProfile::PROVINCE_ID),
                SupplierProfile::CITY_ID => $request->validated(SupplierProfile::CITY_ID),
                SupplierProfile::FACTORY_ADDRESS => $request->validated(SupplierProfile::FACTORY_ADDRESS),
                SupplierProfile::POSTAL_CODE => $request->validated(SupplierProfile::POSTAL_CODE),
                SupplierProfile::IBAN => $request->validated(SupplierProfile::IBAN),
            ]
        );
        $supplierProfile->activities()->sync($request->validated('activity_category_id'));

        // ارسالِ درخواست: از draft (انتخابِ نقش)، rejected و needs_correction به صف
        // می‌رود. `status` عمداً fillable نیست، پس `updateOrCreate` بالا لمسش
        // نمی‌کند. عمداً نه از `approved`: پروفایلِ تأییدشده با یک ویرایشِ ساده
        // تأییدش را از دست نمی‌دهد، و `pending` از قبل در صف است. دلیلِ قبلیِ
        // ادمین مالِ نسخه‌ی قبلیِ پروفایل است و با ارسالِ تازه پاک می‌شود.
        if ($submitting) {
            $supplierProfile->forceFill([
                SupplierProfile::STATUS => SupplierProfile::STATUS_PENDING,
                SupplierProfile::REJECTION_REASON => null,
                SupplierProfile::SUBMITTED_AT => now(),
            ])->save();
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
     * ۱. **کارخانه‌ی باقی‌مانده** — `factories.supplier_user_id` به کاربر اشاره
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

            // نیمه‌ی گم‌شده — بدون این، پروفایل می‌رود و دسترسی می‌ماند.
            $user->removeRole('supplier');
        });

        return success([], __('profile.supplier_deleted'));
    }
}
