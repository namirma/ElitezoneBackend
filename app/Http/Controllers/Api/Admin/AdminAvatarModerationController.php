<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * صفِ تأییدِ تصویرِ ادمین‌ها — فقط مدیرِ ارشد (سطح ۱).
 *
 * تصویرِ ادمین در تیکت‌های پشتیبانی کنارِ نامش به مشتری نشان داده می‌شود. تا وقتی
 * اینجا تأیید نشده، آنچه مشتری می‌بیند تصویرِ قبلیِ تأییدشده است یا تصویرِ پیش‌فرض
 * — هرگز تصویرِ در انتظار.
 */
class AdminAvatarModerationController extends Controller
{
    /** GET /admin/avatar-requests — فهرستِ تصاویرِ در انتظارِ تأیید. */
    public function index(): JsonResponse
    {
        $rows = UserProfile::query()
            ->whereNotNull(UserProfile::AVATAR_PENDING)
            ->with(['user:id,mobile,admin_level'])
            ->get()
            ->map(fn (UserProfile $p) => [
                'user_id' => $p->{UserProfile::USER_ID},
                'name' => trim(($p->{UserProfile::NAME} ?? '').' '.($p->{UserProfile::FAMILY} ?? '')) ?: null,
                // ‏`user_profiles.user_id` در اسکیما NOT NULL است.
                'mobile' => $p->user->{User::MOBILE},
                'admin_level' => $p->user->{User::ADMIN_LEVEL},
                'pending_avatar_url' => $this->url($p->{UserProfile::AVATAR_PENDING}),
                'current_avatar_url' => $this->url($p->{UserProfile::AVATAR}),
            ])
            ->values();

        return success($rows, __('admin.avatar.list_loaded'));
    }

    /** POST /admin/avatar-requests/{user}/approve — انتشارِ تصویر. */
    public function approve(User $user): JsonResponse
    {
        $profile = $this->pendingProfileOrNull($user);
        if (! $profile) {
            return error(__('admin.avatar.no_pending'), [], 422);
        }

        DB::transaction(function () use ($profile) {
            $old = $profile->{UserProfile::AVATAR};
            $pending = $profile->{UserProfile::AVATAR_PENDING};

            $profile->update([
                UserProfile::AVATAR => $pending,
                UserProfile::AVATAR_PENDING => null,
                UserProfile::AVATAR_REJECTION_REASON => null,
                UserProfile::AVATAR_REVIEWED_AT => now(),
                UserProfile::AVATAR_REVIEWED_BY => Auth::id(),
            ]);

            // تصویرِ قبلی بعد از جابه‌جاییِ موفق حذف می‌شود، نه قبل از آن — اگر
            // آپدیت شکست بخورد نباید ادمین بدونِ هیچ تصویری بماند.
            $this->deleteStoredFile($old);
        });

        return success([], __('admin.avatar.approved'));
    }

    /** POST /admin/avatar-requests/{user}/reject — ردِ تصویر با دلیل. */
    public function reject(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate(
            ['reason' => ['required', 'string', 'max:500']],
            ['reason.required' => __('admin.avatar.reason_required')]
        );

        $profile = $this->pendingProfileOrNull($user);
        if (! $profile) {
            return error(__('admin.avatar.no_pending'), [], 422);
        }

        $pending = $profile->{UserProfile::AVATAR_PENDING};

        $profile->update([
            UserProfile::AVATAR_PENDING => null,
            UserProfile::AVATAR_REJECTION_REASON => $validated['reason'],
            UserProfile::AVATAR_REVIEWED_AT => now(),
            UserProfile::AVATAR_REVIEWED_BY => Auth::id(),
        ]);

        // فایلِ ردشده روی دیسک نمی‌ماند؛ نگه‌داشتنش فقط فضا می‌گیرد و چون هیچ
        // مسیری آن را سرو نمی‌کند، ارزشِ ممیزی هم ندارد. دلیلِ رد ثبت شده است.
        $this->deleteStoredFile($pending);

        return success([], __('admin.avatar.rejected'));
    }

    private function pendingProfileOrNull(User $user): ?UserProfile
    {
        $profile = UserProfile::where(UserProfile::USER_ID, $user->{User::ID})->first();

        return $profile && $profile->{UserProfile::AVATAR_PENDING} ? $profile : null;
    }

    private function deleteStoredFile(?string $path): void
    {
        if ($path && ! str_starts_with($path, 'http')) {
            Storage::disk('public')->delete($path);
        }
    }

    private function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return str_starts_with($path, 'http') ? $path : Storage::disk('public')->url($path);
    }
}
