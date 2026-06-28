<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\Profile\EduixRecordResource;
use App\Models\EduixRecord;
use App\Models\User;
use App\Services\EduixScraperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EduixController extends Controller
{
    public function __construct(protected EduixScraperService $scraper) {}

    public function fetch(Request $request): JsonResponse
    {
        $user = Auth::user();

        $hasActiveRecord = $user->eduixRecords()
            ->whereIn(EduixRecord::ADMIN_STATUS, [EduixRecord::STATUS_PENDING, EduixRecord::STATUS_APPROVED])
            ->exists();

        if ($hasActiveRecord) {
            return error(__('education.already_has_active_request'), [], 403);
        }
        $valitadion = $request->validate([
            'national_code' => 'required|string',
            'trace_code' => 'required|string',
        ]);
        if ($user->{User::NATIONAL_CODE} && $user->{User::NATIONAL_CODE} !== $valitadion['national_code']) {
            return error(__('education.national_code_mismatch'), [], 422);
        }

        try {
            $data = $this->scraper->fetchData($valitadion['national_code'], $valitadion['trace_code']);

            $record = EduixRecord::updateOrCreate(
                [
                    EduixRecord::USER_ID => $user->id,
                    EduixRecord::TRACE_CODE => $valitadion['trace_code'],
                ],
                array_merge($data, [
                    EduixRecord::USER_ID => $user->id,
                    EduixRecord::ADMIN_STATUS => EduixRecord::STATUS_PENDING,
                ])
            );

            return success(new EduixRecordResource($record), __('education.fetch_success'));
        } catch (\Throwable $e) {
            Log::error('Eduix fetch error: '.$e->getMessage());

            return error(__('education.fetch_error'), [], 500);
        }
    }

    public function index(): JsonResponse
    {
        $user = Auth::user();
        $records = $user->eduixRecords()->latest()->get();

        return success(EduixRecordResource::collection($records), __('education.list_success'));
    }

    public function destroy(EduixRecord $record): JsonResponse
    {
        if ($record->{EduixRecord::USER_ID} !== Auth::id()) {
            return error(__('auth.forbidden'), [], 403);
        }
        if ($record->{EduixRecord::ADMIN_STATUS} === EduixRecord::STATUS_APPROVED) {
            return error(__('education.delete_denied'), [], 400);
        }

        $record->delete();

        return success([], __('education.delete_success'));
    }
}
