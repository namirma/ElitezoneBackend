<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierProfileRequest;
use App\Http\Resources\ActivityCategoryResource;
use App\Http\Resources\UserResource;
use App\Models\ActivityCategory;
use App\Models\SupplierProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class SupplierController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->getCategories();
    }

    private function getCategories(): JsonResponse
    {
        $categories = ActivityCategory::with('children')->where('parent_id', null)->get();

        return response()->json($this->formatCategories($categories));
    }

    private function formatCategories($categories)
    {
        return $categories->map(function ($category) {
            return [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'children' => $this->formatCategories($category->children)
            ];
        });
    }
    public function store(StoreSupplierProfileRequest $request)
    {
        if (!SupplierProfile::where([SupplierProfile::USER_ID => Auth::id()])->exists())
            ProfileController::removeProfile();

        SupplierProfile::updateOrCreate(
            [
                SupplierProfile::USER_ID => Auth::id(),
            ],
            [
                SupplierProfile::USER_ID => Auth::id(),
                SupplierProfile::ACTIVITY_FIELD => @$request->validated('activity_field'),
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

        return success(new UserResource(User::with('supplier')->find(Auth::id())));

    }

}
