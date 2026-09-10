<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreContractorProfileRequest;
use App\Http\Resources\Profile\UserResource;
use App\Models\ContractorProfile;
use App\Models\User;
use App\Models\UserType; // اضافه شد
use Illuminate\Support\Facades\Auth;

class ContractorController extends Controller
{
    public function store(StoreContractorProfileRequest $request)
    {
        $user = Auth::user();
        // Seeded slug is 'elite_yar' (UserTypeSeeder), not 'consultant' — the old value made
        // $eliteYarTypeId null and never attached the contractor role.
        $eliteYarTypeId = UserType::where('slug', 'elite_yar')->value('id');
        if ($user->type_id == $eliteYarTypeId && ! $user->hasRole('contractor')) {
            $user->addRole('contractor');
        }
        // Load the existing profile so partial updates don't overwrite unsubmitted fields
        // with null. $this->company_name etc. were non-existent controller properties that
        // always evaluated to null (same data-loss bug fixed in Employer/EngineerController).
        $existing = ContractorProfile::where(ContractorProfile::USER_ID, Auth::id())->first();

        ContractorProfile::updateOrCreate(
            [
                ContractorProfile::USER_ID => Auth::id(),
            ],
            [
                ContractorProfile::USER_ID => Auth::id(),
                ContractorProfile::COMPANY_NAME => $request->validated('company_name') ?? $existing?->company_name,
                // ‏`?? true` جا مانده بود و ستون `NOT NULL DEFAULT 1` است: برای
                // کاربری که هنوز پروفایل ندارد، هر دو طرفِ `??` نال می‌شدند و
                // یک **نالِ صریح** در INSERT می‌رفت که دیفالتِ دیتابیس را خنثی
                // می‌کند — نتیجه ۵۰۰ با نشتِ متنِ SQL. همان الگویی که
                // `engineer_member` در خطِ پایین از قبل دارد.
                // ‏`->` و نه `?->`: با یک `??` بعدش، عملگرِ ادغامِ نال دسترسیِ
                // خواص روی null را **سرکوب** می‌کند، پس `?->` بی‌اثر است و
                // larastan آن را `nullsafe.neverNull` می‌گیرد. رفتارِ اجرا یکسان
                // است (آزموده: `$null->x ?? 'd'` بدونِ هیچ اخطاری `'d'` می‌دهد).
                // خطِ `engineer_member` پایین هنوز `?->` دارد چون از قبل در
                // `phpstan-baseline.neon` سرکوب شده؛ عمداً دست‌نخورده ماند تا آن
                // ورودیِ baseline بی‌تطبیق نشود.
                ContractorProfile::NOT_RELATED => $request->validated('not_related') ?? $existing->not_related ?? true,
                ContractorProfile::LICENSE_NUMBER => $request->validated('license_number') ?? $existing?->license_number,
                ContractorProfile::SUPERVISION_BASE => $request->validated('supervision_base') ?? $existing?->supervision_base,
                ContractorProfile::DESIGN_BASE => $request->validated('design_base') ?? $existing?->design_base,
                ContractorProfile::ENGINEER_MEMBER => $request->validated('engineer_member') ?? $existing?->engineer_member ?? false,
                ContractorProfile::ACTIVITY_HISTORY => $request->validated('activity_history') ?? $existing?->activity_history,
                ContractorProfile::ACTIVITY_PROVINCES => $request->validated('activity_provinces') ?? $existing?->activity_provinces,
                ContractorProfile::PROJECT_DONE => $request->validated('project_done') ?? $existing?->project_done,
                ContractorProfile::PROJECT_IN_PROGRESS => $request->validated('project_in_progress') ?? $existing?->project_in_progress,
                ContractorProfile::TRUSTED_CONTRACTOR => $request->validated('trusted_contractor') ?? $existing?->trusted_contractor,
            ]
        );

        return success(new UserResource(User::with('contractor')->find(Auth::id())));
    }
}
