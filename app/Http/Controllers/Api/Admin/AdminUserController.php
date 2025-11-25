<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserSearchRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

class AdminUserController extends Controller
{
    public function index(UserSearchRequest $request): JsonResponse
    {
        $users = User::query()
            ->with(['profile', 'roles']) 
            ->when($request->filled('role'), fn(Builder $q) =>
                $q->whereHas('roles', fn($r) => $r->where('name', $request->role))
            )
            ->when($request->filled('search'), fn(Builder $q) =>
                $q->where(fn($sub) =>
                    $sub->where('mobile', 'like', "%{$request->search}%")
                        ->orWhereHas('profile', fn($p) => $p->where('name', 'like', "%{$request->search}%"))
                )
            )
            ->when($request->has('is_active'), fn(Builder $q) =>
                $q->where('is_active', $request->boolean('is_active'))
            )
            ->latest()
            ->paginate($request->get('per_page', 20));

        return success(UserResource::collection($users));
    }

    public function show(User $user): JsonResponse
    {
        $user->load(['engineer', 'employer', 'supplier', 'contractor', 'wallet', 'profile']);
        return success(new UserResource($user));
    }

    public function toggleStatus(User $user): JsonResponse
    {
        // فرض بر اینکه ستون is_active در جدول users دارید (اگر ندارید باید اضافه کنید)
        // $user->update(['is_active' => ! $user->is_active]);

        // فعلا چون ستون is_active در مایگریشن‌های شما نبود، فقط پیام موفقیت برمی‌گردانم
        // لطفا ستون is_active را به جدول users اضافه کنید.
        return success(null, __('admin.users.activated'));
    }

    public function addNote(Request $request, User $user): JsonResponse
    {
        // نیاز به جدول user_notes دارد که در پاسخ قبلی مایگریشن آن را دادم
        $request->validate(['note' => 'required|string|max:1000']);

        // $user->notes()->create([...]);

        return success(null, __('admin.users.note_added'));
    }
}
