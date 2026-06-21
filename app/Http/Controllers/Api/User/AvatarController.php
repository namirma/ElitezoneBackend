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

        // Remove the previous avatar (skip legacy full-URL values).
        $old = $profile->{UserProfile::AVATAR};
        if ($old && ! str_starts_with($old, 'http')) {
            Storage::disk('public')->delete($old);
        }

        $profile->update([UserProfile::AVATAR => $path]);

        return success(
            ['avatar_url' => Storage::disk('public')->url($path)],
            __('profile.avatar_uploaded')
        );
    }
}
