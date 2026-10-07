<?php

namespace App\Actions\Profile;

use App\Exceptions\BusinessException;
use App\Models\Agreement;
use App\Models\User;
use App\Services\Profile\VirtualEmployerAccount;
use Illuminate\Http\Request;

/**
 * ثبت پذیرش الکترونیکی توافق‌نامه‌ها هنگام انتخاب نقش — طبق «دستورالعمل
 * پیاده‌سازی توافق‌نامه آنلاین الیت یار»:
 *
 *  - سه سند در هر انتخاب نقش پذیرفته می‌شود: شرایط الیت‌زون + حریم خصوصی +
 *    توافق‌نامه اختصاصی نقش (الیت یار / کارفرما / سوپلایر).
 *  - اگر نسخه‌ی فعال سند چک‌باکس‌های الزامی داشته باشد (مثل CHK-01..08 الیت یار)،
 *    یا باید همه‌ی کدها در `accepted_checkboxes` بیایند، یا `terms_accepted=true`
 *    به‌عنوان پذیرش یکجای همه‌ی موارد ثبت شود.
 *  - هر پذیرش به نسخه + هش SHA-256 متنِ همان لحظه گره می‌خورد و snapshot کامل
 *    (موبایل تأییدشده، IP، User-Agent، روش پذیرش) در pivot ذخیره می‌شود.
 *  - append-only: syncWithoutDetaching هرگز پذیرش قبلی را بازنویسی نمی‌کند؛
 *    نسخه‌ی جدیدِ یک سند، ردیف Agreement جدید است و پذیرش تازه می‌گیرد.
 *
 * @throws BusinessException وقتی سندی الزامی است و پذیرفته نشده باشد.
 */
final class SignAgreementAction
{
    public function __construct(private readonly VirtualEmployerAccount $virtualAccounts) {}

    /**
     * @param  string  $roleSlug  One of 'elite_yar' | 'employer' | 'supplier'
     */
    public function execute(User $user, string $roleSlug, Request $request): void
    {
        foreach ($this->slugsFor($roleSlug) as $slug) {
            $agreement = Agreement::latestActiveForSlug($slug);

            if (! $agreement) {
                continue; // سندی برای این اسلاگ منتشر نشده — چیزی برای پذیرش نیست
            }

            $this->accept($user, $agreement, $request, $roleSlug);
        }
    }

    /**
     * پذیرش یک نسخه‌ی مشخص از یک سند — واحد مشترک بین انتخاب نقش و
     * پذیرش مجدد نسخه‌ی جدید (AgreementController::accept).
     *
     * @return bool true اگر پذیرش (جدید یا از قبل موجود) برقرار باشد
     *
     * @throws BusinessException وقتی سند الزامی است و پذیرفته نشده
     */
    public function accept(User $user, Agreement $agreement, Request $request, ?string $roleSlug = null): bool
    {
        // پذیرش قبلاً برای همین نسخه ثبت شده؟ (append-only — دوباره ننویس)
        if ($user->agreements()->whereKey($agreement->id)->exists()) {
            return true;
        }

        $checkedItems = $this->resolveCheckedItems($agreement, $request);

        if ($checkedItems === null) {
            if ($agreement->{Agreement::IS_MANDATORY}) {
                throw new BusinessException(__('auth.agreements.required'));
            }

            return false; // اختیاری و پذیرفته‌نشده — رد شو
        }

        $user->agreements()->syncWithoutDetaching([
            $agreement->id => [
                'accepted_at' => now(),
                'version' => $agreement->{Agreement::VERSION},
                'content_sha256' => $agreement->{Agreement::CONTENT_SHA256},
                'ip_address' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
                'checked_items' => json_encode($checkedItems, JSON_UNESCAPED_UNICODE),
                'acceptance_method' => 'checkbox+otp_login',
                'snapshot' => json_encode([
                    'agreement_type' => $agreement->{Agreement::SLUG},
                    'agreement_version' => $agreement->{Agreement::VERSION},
                    'agreement_sha256' => $agreement->{Agreement::CONTENT_SHA256},
                    'user_id' => $user->id,
                    'mobile_number' => $user->{User::MOBILE},
                    'mobile_verified' => true, // ورود فقط با OTP/رمز روی همین شماره ممکن است
                    'accepted_at' => now()->toIso8601String(),
                    'accepted_ip' => $request->ip(),
                    'user_agent' => $request->header('User-Agent'),
                    'accepted_checkboxes' => array_column($checkedItems, 'code'),
                    'role_slug' => $roleSlug,
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        // حسابِ مجازیِ کارفرما (مدیریت، دورِ هفتم، ۷-۵): «کارفرما تنها باید توافقنامه ها را بپذیرد و ثبت نام پایان می یابد».
        $this->virtualAccounts->claimIfRegistrationComplete($user);

        return true;
    }

    /**
     * سه سندِ قابل پذیرش برای انتخاب این نقش: دو سند عمومی + سند اختصاصی نقش.
     *
     * @return string[]
     */
    private function slugsFor(string $roleSlug): array
    {
        $roleAgreement = match ($roleSlug) {
            'elite_yar' => Agreement::SLUG_ELITE_YAR_TERMS,
            'employer' => Agreement::SLUG_EMPLOYER_TERMS,
            'supplier' => Agreement::SLUG_SUPPLIER_TERMS,
            default => null,
        };

        return array_values(array_filter([
            Agreement::SLUG_ELITEZONE_TERMS,
            Agreement::SLUG_PRIVACY_POLICY,
            $roleAgreement,
        ]));
    }

    /**
     * تعیین چک‌باکس‌های پذیرفته‌شده برای این سند.
     *
     * null یعنی «پذیرفته نشد». برای سند چک‌باکس‌دار، یا همه‌ی کدهای الزامی باید در
     * accepted_checkboxes آمده باشند، یا terms_accepted=true به‌عنوان پذیرش یکجا
     * (همه‌ی چک‌باکس‌ها) پذیرفته می‌شود. برای سند بدون چک‌باکس، terms_accepted کافی است.
     *
     * @return array<int, array{code: string, text: string}>|null
     */
    private function resolveCheckedItems(Agreement $agreement, Request $request): ?array
    {
        $checkboxes = collect($agreement->{Agreement::CHECKBOXES} ?? []);
        $aggregateAccepted = $request->boolean('terms_accepted');

        if ($checkboxes->isEmpty()) {
            return $aggregateAccepted ? [] : null;
        }

        $given = collect($request->input('accepted_checkboxes', []))
            ->filter(fn ($v) => is_string($v))
            ->values();

        $requiredCodes = $checkboxes->pluck('code')->filter();
        $allChecked = $requiredCodes->diff($given)->isEmpty();

        if (! $allChecked && ! $aggregateAccepted) {
            return null;
        }

        // متنِ همان لحظه‌ی هر چک‌باکس هم snapshot می‌شود (الزام سند: پس از پذیرش،
        // متن چک‌باکس‌ها تغییر نکند؛ تغییر متن = نسخه‌ی جدید).
        return $checkboxes
            ->map(fn (array $box) => ['code' => (string) ($box['code'] ?? ''), 'text' => (string) ($box['text'] ?? '')])
            ->values()
            ->all();
    }
}
