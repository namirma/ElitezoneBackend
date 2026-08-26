<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreAvatarRequest;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class AvatarController extends Controller
{
    /**
     * DELETE /api/profile/avatar — remove the current user's profile picture.
     *
     * Deletes the file from the `public` disk and nulls the column.
     * Returns 422 when no avatar is set (frontend handles both gracefully).
     */
    public function destroy(): JsonResponse
    {
        $profile = UserProfile::where(UserProfile::USER_ID, Auth::id())->first();

        if (! $profile || ! $profile->{UserProfile::AVATAR}) {
            return error(__('profile.avatar_not_found'), [], 422);
        }

        $path = $profile->{UserProfile::AVATAR};
        if (! str_starts_with($path, 'http')) {
            Storage::disk('public')->delete($path);
        }

        $profile->update([UserProfile::AVATAR => null]);

        return success([], __('profile.avatar_deleted'));
    }

    /**
     * POST /api/profile/avatar — upload (or replace) the user's profile picture.
     *
     * Avatars are public images (rendered via <img src>), so they live on the `public`
     * disk and the response returns the full URL. The old file is deleted on replace.
     * Requires `php artisan storage:link` on the server so /storage serves the file.
     */
    public function store(StoreAvatarRequest $request): JsonResponse
    {
        $user = Auth::user();

        $path = $request->file('avatar')->store('avatars', 'public');
        if (! $path) {
            return error(__('profile.avatar_upload_failed'), [], 500);
        }

        // firstOrCreate so a user without a profile row yet can still set an avatar.
        $profile = UserProfile::firstOrCreate(
            [UserProfile::USER_ID => $user->id],
            [
                UserProfile::NATIONAL_CODE => $user->{User::NATIONAL_CODE},
            ]
        );

        // ── ادمین‌ها از صفِ تأیید رد می‌شوند، کاربرانِ عادی نه ────────────────
        // تصویرِ ادمین در تیکت‌های پشتیبانی کنارِ نامش به مشتری نشان داده می‌شود،
        // پس چهره‌ی برند است. مدیرِ ارشد از این قاعده مستثناست؛ کسی بالادستِ او
        // نیست که تأیید کند و صفی که هیچ‌کس تأییدش نمی‌کند یعنی قفلِ دائمی.
        if ($user->hasRole('admin') && ! $user->isOwnerAdmin()) {
            $this->deleteStoredFile($profile->{UserProfile::AVATAR_PENDING});

            $profile->update([
                UserProfile::AVATAR_PENDING => $path,
                // تصمیمِ قبلی دیگر معتبر نیست: تصویرِ تازه یعنی درخواستِ تازه.
                UserProfile::AVATAR_REJECTION_REASON => null,
                UserProfile::AVATAR_REVIEWED_AT => null,
                UserProfile::AVATAR_REVIEWED_BY => null,
            ]);

            return success(
                [
                    'pending' => true,
                    // آدرسِ تصویرِ در انتظار فقط به خودِ ادمین برمی‌گردد تا در پنل
                    // پیش‌نمایشش را ببیند؛ هیچ مصرف‌کننده‌ی عمومی‌ای آن را نمی‌گیرد.
                    'pending_avatar_url' => Storage::disk('public')->url($path),
                    'avatar_url' => $this->publicAvatarUrl($profile),
                ],
                __('profile.avatar_pending_review')
            );
        }

        // Remove the previous avatar (skip legacy full-URL values).
        $this->deleteStoredFile($profile->{UserProfile::AVATAR});

        $profile->update([UserProfile::AVATAR => $path]);

        return success(
            ['pending' => false, 'avatar_url' => Storage::disk('public')->url($path)],
            __('profile.avatar_uploaded')
        );
    }

    /**
     * حذفِ امنِ فایلِ ذخیره‌شده — مقادیرِ لگاسیِ full-URL را دست نمی‌زند.
     */
    private function deleteStoredFile(?string $path): void
    {
        if ($path && ! str_starts_with($path, 'http')) {
            Storage::disk('public')->delete($path);
        }
    }

    private function publicAvatarUrl(UserProfile $profile): ?string
    {
        $avatar = $profile->{UserProfile::AVATAR};
        if (! $avatar) {
            return null;
        }

        return str_starts_with($avatar, 'http') ? $avatar : Storage::disk('public')->url($avatar);
    }
}
