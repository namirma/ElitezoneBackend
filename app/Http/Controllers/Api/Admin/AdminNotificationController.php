<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendAdminNotificationRequest;
use App\Http\Resources\Shared\UserMessageResource;
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

    // POST /admin/notifications/send
    public function send(SendAdminNotificationRequest $request): JsonResponse
    {
        $subject = $request->string('subject');
        $body = $request->string('body');
        $senderId = Auth::id();
        $now = now();
        $sentCount = 0;

        $buildRows = function (array $userIds) use ($subject, $body, $senderId, $now): array {
            return array_map(fn (int $id) => [
                UserNotification::USER_ID => $id,
                UserNotification::SENDER_ID => $senderId,
                UserNotification::SUBJECT => $subject,
                UserNotification::BODY => $body,
                'created_at' => $now,
                'updated_at' => $now,
            ], $userIds);
        };

        if ($request->boolean('all')) {
            User::query()
                ->where(fn ($q) => $q->where(User::IS_ACTIVE, true)->orWhereNull(User::IS_ACTIVE))
                ->select(User::ID)
                ->chunkById(500, function ($users) use ($buildRows, &$sentCount) {
                    UserNotification::insert($buildRows($users->pluck(User::ID)->all()));
                    $sentCount += $users->count();
                });
        } else {
            $userIds = array_map('intval', $request->input('user_ids', []));

            foreach (array_chunk($userIds, 500) as $chunk) {
                UserNotification::insert($buildRows($chunk));
                $sentCount += count($chunk);
            }
        }

        return success(['sent_count' => $sentCount], __('admin.notifications.sent'));
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
