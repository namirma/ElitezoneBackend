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

        $path = $data['file']->store('job_experiences', 'local');
        if (! $path) {
            return error(__('credential.file_upload_error'), [], 500);
        }

        $job = $user->jobExperiences()->create([
            JobExperience::TITLE             => $data[JobExperience::TITLE],
            JobExperience::ORGANIZATION_NAME => $data[JobExperience::ORGANIZATION_NAME] ?? null,
            JobExperience::PROJECT_ADDRESS   => $data[JobExperience::PROJECT_ADDRESS] ?? null,
            JobExperience::START_DATE        => $data[JobExperience::START_DATE] ?? null,
            JobExperience::END_DATE          => $data[JobExperience::END_DATE] ?? null,
            JobExperience::FILE_PATH         => $path,
            JobExperience::STATUS            => JobExperience::STATUS_PENDING,
        ]);

        return success(
            new JobExperienceResource($job),
            __('job.store_success'),
            201
        );
    }

    public function download(JobExperience $jobExperience)
    {
        if ($jobExperience->{JobExperience::USER_ID} !== Auth::id()) {
            return error(__('auth.forbidden'), [], 403);
        }

        $path = $jobExperience->{JobExperience::FILE_PATH};

        if (! $path || ! Storage::disk('local')->exists($path)) {
            return error(__('job.file_not_found'), [], 404);
        }

        return Storage::disk('local')->download($path);
    }

    public function destroy(JobExperience $jobExperience): JsonResponse
    {
        if ($jobExperience->{JobExperience::USER_ID} !== Auth::id()) {
            return error(__('auth.forbidden'), [], 403);
        }

        if ($jobExperience->{JobExperience::STATUS} === JobExperience::STATUS_APPROVED) {
            return error(__('job.delete_denied'), [], 400);
        }

        $path = $jobExperience->{JobExperience::FILE_PATH};
        if ($path && ! str_starts_with($path, 'http')) {
            Storage::disk('local')->delete($path);
        }

        $jobExperience->delete();

        return success([], __('job.delete_success'));
    }
}
