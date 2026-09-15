<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SmsLogFilterRequest;
use App\Http\Resources\Content\SmsLogResource;
use App\Models\SmsLog;
use Illuminate\Http\JsonResponse;

/**
 * پنل مدیریت — گزارشِ ارسالِ پیامک: هر تلاش با نتیجه‌اش (رفته / خارج از allowlist /
 * خاموش / شرط / خطا). فقط خواندنی؛ ردیف‌ها را `SmsService` و `SendSmsJob` می‌نویسند.
 */
class AdminSmsLogController extends Controller
{
    // GET /admin/sms-logs
    public function index(SmsLogFilterRequest $request): JsonResponse
    {
        $query = SmsLog::query()->latest(SmsLog::ID);

        if ($request->filled('mobile')) {
            $query->where(SmsLog::MOBILE, 'like', '%'.$request->string('mobile')->trim().'%');
        }

        if ($request->filled('key')) {
            $query->where(SmsLog::TEMPLATE_KEY, $request->string('key'));
        }

        if ($request->filled('status')) {
            $query->where(SmsLog::STATUS, $request->string('status'));
        }

        if ($request->filled('from')) {
            $query->where(SmsLog::CREATED_AT, '>=', $request->string('from').' 00:00:00');
        }

        if ($request->filled('to')) {
            $query->where(SmsLog::CREATED_AT, '<=', $request->string('to').' 23:59:59');
        }

        return success(SmsLogResource::collection($query->paginate($this->perPage())), __('admin.sms.logs_received'));
    }
}
