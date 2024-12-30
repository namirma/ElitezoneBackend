<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierProfileRequest;
use App\Http\Resources\SupplierCategoryResource;
use App\Http\Resources\SupplierDataResource;
use App\Http\Resources\SupplierProfileResource;
use App\Http\Resources\UserResource;
use App\Models\SupplierActivityCategory;
use App\Models\SupplierProfile;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class SupplierController extends Controller
{
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
    public function getCategories()
    {
        return success(SupplierCategoryResource::collection(SupplierActivityCategory::getAll()));
    }

    public function store(StoreSupplierProfileRequest $request)
    {
        if (!SupplierProfile::where([SupplierProfile::USER_ID => Auth::id()])->exists())
            ProfileController::removeProfile();

        $supplierProfile = SupplierProfile::updateOrCreate(
            [
                SupplierProfile::USER_ID => Auth::id(),
            ],
            [
                SupplierProfile::USER_ID => Auth::id(),
                SupplierProfile::ACTIVITY_DESCRIPTION => $request->validated('activity_description') ?? $this->activity_description ?? null,
                SupplierProfile::BRANDS => $request->validated('brands') ?? null,
                SupplierProfile::BRANDS_NOT_RELATED => $request->validated('brands_not_related') ?? 1, // default to true
                SupplierProfile::ACTIVITY_HISTORY => $request->validated('activity_history') ?? null,
                SupplierProfile::ACTIVITY_PROVINCES => $request->validated('activity_provinces') ?? null,
                SupplierProfile::PRODUCTION_AMOUNT => $request->validated('production_amount') ?? null,
                SupplierProfile::STANDARDS => $request->validated('standards') ?? null,
                SupplierProfile::STANDARDS_NOT_RELATED => $request->validated('standards_not_related') ?? 1, // default to true
                SupplierProfile::WEBSITE => $request->validated('website') ?? null,
                SupplierProfile::WEBSITE_NOT_RELATED => $request->validated('website_not_related') ?? 1, // default to true
                SupplierProfile::COMPANY => $request->validated('company') ?? null,

            ]);
        if ($request->has('activity_category_id')) {
            $supplierProfile->activities()->sync($request->activity_category_id);
        }
        return success(new UserResource(User::with('supplier')->find(Auth::id())));

    }

}
