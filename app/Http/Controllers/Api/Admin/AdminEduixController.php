<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminEduixResource;
use App\Models\EduixRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * پنل مدیریت — صفِ تأییدِ «مدارک تحصیلی» (Eduix).
 *
 * گپِ عملیاتی: ستونِ `admin_status` دقیقاً برای تصمیمِ ادمین ساخته شده بود، اما هیچ
 * endpointِ ادمینی آن را ست نمی‌کرد؛ هر رکورد در pending می‌ماند. این کنترلر صف، نمایش
 * و تصمیم (approve/reject + دلیل) را اضافه می‌کند. مرتب‌سازی FIFO = منتظرترین، اول.
 */
class AdminEduixController extends Controller
{
    private const STATUSES = [
        EduixRecord::STATUS_PENDING,
        EduixRecord::STATUS_APPROVED,
        EduixRecord::STATUS_REJECTED,
    ];

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in([...self::STATUSES, 'all'])],
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $status = $request->input('status', EduixRecord::STATUS_PENDING);

        $records = EduixRecord::query()
            ->when($status !== 'all', fn ($q) => $q->where(EduixRecord::ADMIN_STATUS, $status))
            ->when($request->filled('q'), fn ($q) => $q->whereHas(
                'user',
                fn ($u) => $u->where('mobile', 'like', '%'.$request->string('q').'%')
                    ->orWhereHas('profile', fn ($p) => $p->where('name', 'like', '%'.$request->string('q').'%'))
            ))
            ->with('user.profile')
            ->oldest()
            ->paginate($this->perPage(30));

        return success(AdminEduixResource::collection($records), __('admin.education_records.list_received'));
    }

    public function show(EduixRecord $eduixRecord): JsonResponse
    {
        return success(
            new AdminEduixResource($eduixRecord->load('user.profile')),
            __('admin.education_records.received')
        );
    }

    public function approve(EduixRecord $eduixRecord): JsonResponse
    {
        $eduixRecord->update([
            EduixRecord::ADMIN_STATUS => EduixRecord::STATUS_APPROVED,
            EduixRecord::REJECTION_REASON => null,
        ]);

        return success(
            new AdminEduixResource($eduixRecord->load('user.profile')),
            __('admin.education_records.approved')
        );
    }

    public function reject(Request $request, EduixRecord $eduixRecord): JsonResponse
    {
        $data = $request->validate(
            ['rejection_reason' => ['required', 'string', 'max:1000']],
            ['rejection_reason.required' => __('admin.education_records.reason_required')]
        );

        $eduixRecord->update([
            EduixRecord::ADMIN_STATUS => EduixRecord::STATUS_REJECTED,
            EduixRecord::REJECTION_REASON => $data['rejection_reason'],
        ]);

        return success(
            new AdminEduixResource($eduixRecord->load('user.profile')),
            __('admin.education_records.rejected')
        );
    }
}
