<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminJobExperienceResource;
use App\Models\JobExperience;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * پنل مدیریت — صفِ تأییدِ «اطلاعات شغلی» الیت یاران.
 *
 * این کنترلر گپِ عملیاتی را می‌بندد: کاربر سابقه‌ی شغلی را با مدرک آپلود می‌کند و رکورد
 * در وضعیت pending می‌ماند. تا پیش از این هیچ راهی نبود که ادمین صفِ منتظرها را ببیند،
 * مدرک را باز کند یا تصمیم بگیرد. حالا:
 *   - index: صف بر اساس «قدیمی‌ترین اول» (FIFO = منتظرترین، اولویت‌دار) + شمار روزِ انتظار.
 *   - download: دانلودِ مدرک با دسترسی ادمین (روتِ کاربر owner-only بود و برای ادمین ۴۰۳ می‌داد).
 *   - approve / reject: تصمیمِ رکوردی که status را ست می‌کند (+ دلیلِ رد).
 */
class AdminJobExperienceController extends Controller
{
    private const STATUSES = [
        JobExperience::STATUS_PENDING,
        JobExperience::STATUS_APPROVED,
        JobExperience::STATUS_REJECTED,
    ];

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in([...self::STATUSES, 'all'])],
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $status = $request->input('status', JobExperience::STATUS_PENDING);

        $records = JobExperience::query()
            ->when($status !== 'all', fn ($q) => $q->where(JobExperience::STATUS, $status))
            ->when($request->filled('q'), fn ($q) => $q->whereHas(
                'user',
                fn ($u) => $u->where('mobile', 'like', '%'.$request->string('q').'%')
                    ->orWhereHas('profile', fn ($p) => $p->where('name', 'like', '%'.$request->string('q').'%'))
            ))
            ->with('user.profile')
            ->oldest() // قدیمی‌ترین اول → منتظرترین در صدرِ صف (اولویت)
            ->paginate($this->perPage(30));

        return success(AdminJobExperienceResource::collection($records), __('admin.job_experiences.list_received'));
    }

    public function show(JobExperience $jobExperience): JsonResponse
    {
        return success(
            new AdminJobExperienceResource($jobExperience->load('user.profile')),
            __('admin.job_experiences.received')
        );
    }

    /** دانلودِ مدرکِ سابقه‌ی شغلی با دسترسی ادمین (دیسک خصوصیِ local). */
    public function download(JobExperience $jobExperience): StreamedResponse|JsonResponse
    {
        $path = $jobExperience->{JobExperience::FILE_PATH};

        if (! $path || str_starts_with($path, 'http') || ! Storage::disk('local')->exists($path)) {
            return error(__('admin.job_experiences.file_not_found'), [], 404);
        }

        return Storage::disk('local')->download($path);
    }

    public function approve(JobExperience $jobExperience): JsonResponse
    {
        $jobExperience->update([
            JobExperience::STATUS => JobExperience::STATUS_APPROVED,
            JobExperience::REJECTION_REASON => null,
        ]);

        return success(
            new AdminJobExperienceResource($jobExperience->load('user.profile')),
            __('admin.job_experiences.approved')
        );
    }

    public function reject(Request $request, JobExperience $jobExperience): JsonResponse
    {
        $data = $request->validate(
            ['rejection_reason' => ['required', 'string', 'max:1000']],
            ['rejection_reason.required' => __('admin.job_experiences.reason_required')]
        );

        $jobExperience->update([
            JobExperience::STATUS => JobExperience::STATUS_REJECTED,
            JobExperience::REJECTION_REASON => $data['rejection_reason'],
        ]);

        return success(
            new AdminJobExperienceResource($jobExperience->load('user.profile')),
            __('admin.job_experiences.rejected')
        );
    }
}
