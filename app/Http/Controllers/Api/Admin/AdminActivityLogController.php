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
            // allowlist باید با مدل‌هایی که واقعاً activity()->performedOn روی آن‌ها صدا
            // زده می‌شود هم‌گام بماند؛ Coupon و SupplierProfile بعداً لاگ‌دار شدند اما در
            // این لیست نبودند و قابلِ فیلتر نبودند.
            $allowed = ['Order', 'User', 'WalletTransaction', 'Ticket', 'Wallet', 'Coupon', 'SupplierProfile'];
            $type = ucfirst($request->subject_type);
            if (! in_array($type, $allowed, true)) {
                return error(__('admin.invalid_subject_type'), [], 422);
            }
            $query->where('subject_type', 'App\\Models\\'.$type);
        }
        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }
        if ($request->filled('causer_id')) {
            $query->where('causer_id', $request->causer_id);
        }

        $logs = $query->latest()->paginate($this->perPage());

        return success(ActivityLogResource::collection($logs), __('admin.activity_log_received'));
    }
}
