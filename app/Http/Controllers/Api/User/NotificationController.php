<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\Shared\UserMessageResource;
use App\Models\UserNotification;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Manages in-app notifications (UserNotification) for the authenticated user.
 */
class NotificationController extends Controller
{
    /**
     * List notifications with optional read/unread filter.
     */
    public function index(Request $request): JsonResponse
    {
        $query = UserNotification::where(UserNotification::USER_ID, Auth::id());

        if ($request->input('filter') === 'unread') {
            $query->whereNull(UserNotification::READ_AT);
        } elseif ($request->input('filter') === 'read') {
            $query->whereNotNull(UserNotification::READ_AT);
        }

        $items = $query->latest()->paginate(20);

        return success(
            UserMessageResource::collection($items)->response()->getData(true),
            __('global.received_successfully')
        );
    }

    /**
     * Return the count of unread notifications (e.g. for the header bell icon).
     */
    public function unreadCount(): JsonResponse
    {
        $count = UserNotification::where(UserNotification::USER_ID, Auth::id())
            ->whereNull(UserNotification::READ_AT)
            ->count();

        return success(['unread_count' => $count], __('global.received_successfully'));
    }

    /**
     * Mark a single notification as read.
     */
    public function markRead(UserNotification $userNotification): JsonResponse
    {
        if ($userNotification->{UserNotification::USER_ID} !== Auth::id()) {
            return error(__('auth.forbidden'), [], 403);
        }

        if (is_null($userNotification->{UserNotification::READ_AT})) {
            $userNotification->update([UserNotification::READ_AT => Carbon::now()]);
        }

        return success(new UserMessageResource($userNotification), __('profile.message_marked_read'));
    }

    /**
     * Mark ALL notifications as read.
     */
    public function markAllRead(): JsonResponse
    {
        $updatedCount = UserNotification::where(UserNotification::USER_ID, Auth::id())
            ->whereNull(UserNotification::READ_AT)
            ->update([UserNotification::READ_AT => Carbon::now()]);

        return success(['updated_count' => $updatedCount], __('profile.all_messages_marked_read'));
    }

    /**
     * Delete a single notification.
     */
    public function destroy(UserNotification $userNotification): JsonResponse
    {
        if ($userNotification->{UserNotification::USER_ID} !== Auth::id()) {
            return error(__('auth.forbidden'), [], 403);
        }

        $userNotification->delete();

        return success([], __('global.deleted_successfully'));
    }
}
