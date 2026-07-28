<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreSupplierProfileRequest;
use App\Http\Resources\Profile\UserResource;
use App\Http\Resources\Supplier\SupplierCategoryResource;
use App\Http\Resources\Supplier\SupplierProfileResource;
use App\Models\SupplierActivityCategory;
use App\Models\SupplierProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

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

    /**
     * پروفایل سوپلایریِ **خودِ کاربر جاری** — شامل `status`.
     *
     * چرا لازم است: نقشِ `supplier` فقط با تأییدِ ادمین وصل می‌شود، و
     * `ProfileController` رابطهٔ سوپلایر را هم فقط بر همان اساس لود می‌کند. نتیجه
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

    public function destroy(): JsonResponse
    {
        $profile = SupplierProfile::where(SupplierProfile::USER_ID, Auth::id())->first();

        if (! $profile) {
            return error(__('profile.supplier_not_found'), [], 404);
        }

        $profile->activities()->detach();
        $profile->delete();

        return success([], __('profile.supplier_deleted'));
    }
}
