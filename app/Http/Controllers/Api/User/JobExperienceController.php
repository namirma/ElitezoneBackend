<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;

use App\Http\Requests\Profile\StoreJobExperienceRequest;
use App\Http\Resources\Profile\JobExperienceResource;
use App\Models\JobExperience;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class JobExperienceController extends Controller
{
    public function index(): JsonResponse
    {
        $user = Auth::user();
        $jobs = $user->jobExperiences()->latest()->get();

        return success(
            JobExperienceResource::collection($jobs),
            __('job.list_success')
        );
    }

    public function store(StoreJobExperienceRequest $request): JsonResponse
    {
        $user = Auth::user();

        $hasActiveJob = $user->jobExperiences()
            ->whereIn(JobExperience::STATUS, [JobExperience::STATUS_PENDING, JobExperience::STATUS_APPROVED])
            ->exists();

        if ($hasActiveJob) {
            return error(__('job.already_has_active_request'), [], 403);
        }
        $data = $request->validated();

        $path = $data['file']->store('job_experiences', 'public');
        if (! $path) {
            return error(__('credential.file_upload_error'), [], 500);
        }
        $url = Storage::disk('public')->url($path);

        $job = $user->jobExperiences()->create([
            JobExperience::TITLE => $data[JobExperience::TITLE],
            JobExperience::ORGANIZATION_NAME => $data[JobExperience::ORGANIZATION_NAME] ?? null,
            JobExperience::PROJECT_ADDRESS => $data[JobExperience::PROJECT_ADDRESS] ?? null,
            JobExperience::START_DATE => $data[JobExperience::START_DATE] ?? null,
            JobExperience::END_DATE => $data[JobExperience::END_DATE] ?? null,
            JobExperience::FILE_PATH => $url,
            JobExperience::STATUS => JobExperience::STATUS_PENDING,
        ]);

        return success(
            new JobExperienceResource($job),
            __('job.store_success'),
            201
        );
    }

    public function destroy(JobExperience $jobExperience): JsonResponse
    {
        if ($jobExperience->{JobExperience::USER_ID} !== Auth::id()) {
            return error(__('auth.forbidden'), [], 403);
        }

        if ($jobExperience->{JobExperience::STATUS} === JobExperience::STATUS_APPROVED) {
            return error(__('job.delete_denied'), [], 400);
        }

        // TODO: حذف فایل از Storage
        // Storage::disk('public')->delete(...);

        $jobExperience->delete();

        return success([], __('job.delete_success'));
    }
}
