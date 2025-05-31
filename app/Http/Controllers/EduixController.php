<?php

namespace App\Http\Controllers;

use App\Http\Resources\EduixRecordResource;
use App\Models\EduixRecord;
use App\Services\EduixScraperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EduixController extends Controller
{
    public function __construct(protected EduixScraperService $scraper) {}

    public function fetch(Request $request): JsonResponse
    {
        $v = $request->validate([
            'national_code' => 'required|string',
            'trace_code'    => 'required|string',
        ]);

        try {
            $data = $this->scraper->fetchData($v['national_code'], $v['trace_code']);

            $record = EduixRecord::updateOrCreate(
                ['national_code' => $v['national_code'], 'trace_code' => $v['trace_code']],
                $data
            );

            return success(new EduixRecordResource($record));
        } catch (\Throwable $e) {
            Log::error('Eduix fetch error: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'مشکل در دریافت اطلاعات. لطفا دوباره تلاش کنید.',
            ], 500);
        }
    }
}
