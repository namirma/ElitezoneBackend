<?php

namespace App\Http\Controllers;

use App\Models\PriceFilterCategory;
use App\Models\PurchaseParameterValue;
use Illuminate\Http\Request;

class ParameterDetectionController extends Controller
{
    public function detectPriceFilters(Request $request)
    {
        $request->validate([
            'customer_type' => 'required|string',
            'consultant_status' => 'required|string',
            'purchase_purpose' => 'required|string',
        ]);

        $customerType = PurchaseParameterValue::where('slug', $request->customer_type)->first();
        $consultantStatus = PurchaseParameterValue::where('slug', $request->consultant_status)->first();
        $purchasePurpose = PurchaseParameterValue::where('slug', $request->purchase_purpose)->first();

        if (!$customerType || !$consultantStatus) {
            return response()->json([
                'status' => 'error',
                'message' => 'پارامترهای نامعتبر'
            ], 400);
        }

        $filterCategoryCode = null;

        if ($customerType->code === 'employer') {
            if ($consultantStatus->code === 'with_consultant') {
                $filterCategoryCode = 'employer_with_consultant';
            } else {
                $filterCategoryCode = 'employer_without_consultant';
            }
        } else if ($customerType->code === 'engineer') {
            if ($consultantStatus->code === 'with_consultant') {
                $filterCategoryCode = 'engineer_with_consultant';
            }
        } else if ($customerType->code === 'contractor') {
            if ($consultantStatus->code === 'with_consultant') {
                $filterCategoryCode = 'contractor_with_consultant';
            }
        }

        $priceFilterCategory = null;
        if ($filterCategoryCode) {
            $priceFilterCategory = PriceFilterCategory::where('code', $filterCategoryCode)->first();
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'customer_type' => $customerType,
                'consultant_status' => $consultantStatus,
                'purchase_purpose' => $purchasePurpose,
                'price_filter_category' => $priceFilterCategory
            ]
        ]);
    }}
