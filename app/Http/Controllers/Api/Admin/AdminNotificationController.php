<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendAdminNotificationRequest;
use App\Http\Resources\Shared\UserMessageResource;
use App\Jobs\Notification\SendBulkNotificationJob;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * پنل مدیریت — اعلان‌های درون‌برنامه‌ای (ماژول ۵ نیازمندی فرانت BackOffice).
 *
 * ارسال یا به همه کاربران (all=true) یا به لیست user_ids؛ درج گروهی chunk-شده
 * تا با تعداد زیاد کاربر حافظه/کوئری منفجر نشود.
 */
class AdminNotificationController extends Controller
{
    // GET /admin/notifications
    public function index(Request $request): JsonResponse
    {
        $query = UserNotification::query()->orderByDesc(UserNotification::ID);

        if ($request->filled('user_id')) {
            $query->where(UserNotification::USER_ID, $request->integer('user_id'));
        }

        return success(UserMessageResource::collection($query->paginate($this->perPage())), __('admin.notifications.list_received'));
    }

    // POST /admin/notifications/send — ارسال واقعی در صف (SendBulkNotificationJob)
    public function send(SendAdminNotificationRequest $request): JsonResponse
    {
        $all = $request->boolean('all');
        $userIds = $all ? null : array_map('intval', $request->input('user_ids', []));

        $targetCount = $all
            ? User::where(fn ($q) => $q->where(User::IS_ACTIVE, true)->orWhereNull(User::IS_ACTIVE))->count()
            : count($userIds);

        SendBulkNotificationJob::dispatch(
            (string) $request->string('subject'),
            (string) $request->string('body'),
            $userIds,
            Auth::id(),
        );

        return success(['queued' => true, 'target_count' => $targetCount], __('admin.notifications.queued'));
    }

    // GET /admin/notifications/stats
    public function stats(): JsonResponse
    {
        $total = UserNotification::count();
        $read = UserNotification::whereNotNull(UserNotification::READ_AT)->count();

        return success([
            'total' => $total,
            'read' => $read,
            'unread' => $total - $read,
            'sent_today' => UserNotification::whereDate(UserNotification::CREATED_AT, today())->count(),
        ], __('admin.notifications.stats_received'));
    }
}
