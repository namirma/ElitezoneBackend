<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Shared\ActivityLogResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class AdminActivityLogController extends Controller
{
    /**
     * لیست کلی فعالیت‌های سیستم
     */
    public function index(Request $request): JsonResponse
    {
        $query = Activity::query()->with('causer.profile');

        if ($request->filled('subject_type')) {
            $modelClass = 'App\\Models\\'.ucfirst($request->subject_type);
            $query->where('subject_type', $modelClass);
        }
        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }
        if ($request->filled('causer_id')) {
            $query->where('causer_id', $request->causer_id);
        }

        $logs = $query->latest()->paginate($request->input('per_page', 20));

        return success(ActivityLogResource::collection($logs), '.گزارش فعالیت‌ها دریافت شد');
    }
}
