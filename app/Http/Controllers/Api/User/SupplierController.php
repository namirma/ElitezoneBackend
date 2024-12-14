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
                SupplierProfile::ACTIVITY_DESCRIPTION => @$request->validated('activity_description'),
                SupplierProfile::BRANDS => @$request->validated('brands'),
                SupplierProfile::BRANDS_NOT_RELATED => @$request->validated('brands_not_related'),
                SupplierProfile::ACTIVITY_HISTORY => @$request->validated('activity_history'),
                SupplierProfile::ACTIVITY_PROVINCES => @$request->validated('activity_provinces'),
                SupplierProfile::PRODUCTION_AMOUNT => @$request->validated('production_amount'),
                SupplierProfile::STANDARDS => @$request->validated('standards'),
                SupplierProfile::STANDARDS_NOT_RELATED => @$request->validated('standards_not_related'),
                SupplierProfile::WEBSITE => @$request->validated('website'),
                SupplierProfile::WEBSITE_NOT_RELATED => @$request->validated('website_not_related'),
                SupplierProfile::COMPANY => @$request->validated('company'),

            ]);
        if ($request->has('activity_category_id')) {
            $supplierProfile->activities()->sync($request->activity_category_id);
        }
        return success(new UserResource(User::with('supplier')->find(Auth::id())));

    }

}
