<?php

namespace App\Http\Controllers;

use App\Actions\Profile\SignAgreementAction;
use App\Models\Agreement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Mccarlosen\LaravelMpdf\Facades\LaravelMpdf as PDF;
use Symfony\Component\HttpFoundation\Response;

/**
 * توافق‌نامه‌های آنلاین — طبق «دستورالعمل پیاده‌سازی توافق‌نامه آنلاین الیت یار»:
 *
 *  - show (عمومی): آخرین نسخه‌ی فعال یک سند برای صفحه‌ی پذیرش — متن کامل،
 *    شماره نسخه، تاریخ لازم‌الاجرا، هش و چک‌باکس‌های الزامی (CHK-01..08).
 *  - publicPdf (عمومی): PDF آخرین نسخه‌ی فعال (لینک دانلود صفحه‌ی پذیرش).
 *  - myAcceptances (auth): رسید پذیرش‌های کاربر برای پنل.
 *  - pending (auth): سندهایی که نسخه‌ی جدیدشان نیاز به پذیرش مجدد فعال دارد
 *    (بند ۱۲ دستورالعمل — رفتار سیستم در تغییر نسخه).
 *  - accept (auth): پذیرش فعال آخرین نسخه‌ی یک سند (re-acceptance).
 *  - acceptedPdf (auth): PDF «همان نسخه‌ای که کاربر پذیرفته» — نه آخرین نسخه
 *    (الزام صریح بند ۹ دستورالعمل).
 */
class AgreementController extends Controller
{
    /** GET /agreements/{slug} — آخرین نسخه فعال برای نمایش و پذیرش */
    public function show(string $slug): JsonResponse
    {
        $agreement = Agreement::latestActiveForSlug($slug);

        if (! $agreement) {
            return error(__('agreements.not_found'), [], 404);
        }

        return success([
            'slug' => $agreement->{Agreement::SLUG},
            'title' => $agreement->{Agreement::TITLE},
            'version' => $agreement->{Agreement::VERSION},
            'effective_at' => $agreement->{Agreement::EFFECTIVE_AT}?->toDateString(),
            'is_mandatory' => (bool) $agreement->{Agreement::IS_MANDATORY},
            'content' => $agreement->{Agreement::CONTENT},
            'content_sha256' => $agreement->{Agreement::CONTENT_SHA256},
            'checkboxes' => $agreement->{Agreement::CHECKBOXES} ?? [],
        ], __('agreements.received'));
    }

    /** GET /agreements/{slug}/pdf — PDF آخرین نسخه فعال (لینک دانلود صفحه پذیرش) */
    public function publicPdf(string $slug): Response|JsonResponse
    {
        $agreement = Agreement::latestActiveForSlug($slug);

        if (! $agreement) {
            return error(__('agreements.not_found'), [], 404);
        }

        return $this->renderPdf($agreement);
    }

    /** GET /profile/agreements — رسید پذیرش‌های کاربر (نسخه‌ی پذیرفته‌شده، نه آخرین) */
    public function myAcceptances(): JsonResponse
    {
        $accepted = Auth::user()->agreements()->get()->map(function (Agreement $agreement) {
            $pivot = $agreement->pivot;

            return [
                'slug' => $agreement->{Agreement::SLUG},
                'title' => $agreement->{Agreement::TITLE},
                'accepted_version' => $pivot->version ?? $agreement->{Agreement::VERSION},
                'accepted_sha256' => $pivot->content_sha256 ?? $agreement->{Agreement::CONTENT_SHA256},
                'accepted_at' => $pivot->accepted_at,
                'acceptance_method' => $pivot->acceptance_method,
                'checked_items' => $pivot->checked_items ? json_decode($pivot->checked_items, true) : [],
                // کد رهگیری پذیرش برای رسید پنل
                'acceptance_id' => $pivot->id ?? null,
            ];
        })->values();

        return success($accepted, __('agreements.my_list'));
    }

    /**
     * GET /profile/agreements/pending — سندهای نیازمند پذیرش (مجدد)
     *
     * برای هر اسلاگِ مرتبط با کاربر (دو سند عمومی + سندهای نقش‌هایش)، اگر آخرین
     * نسخه‌ی فعالِ الزامی را نپذیرفته باشد، سند در این فهرست می‌آید. فرانت باید
     * پس از ورود این endpoint را چک کند و تا خالی‌شدن فهرست، پذیرش فعال بگیرد.
     */
    public function pending(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $pendingSlugs = collect($this->relevantSlugsFor($user))
            ->filter(fn (string $slug) => ! $user->hasAcceptedLatestAgreement($slug))
            ->values();

        $pending = $pendingSlugs->map(function (string $slug) {
            $agreement = Agreement::latestActiveForSlug($slug);

            return [
                'slug' => $agreement->{Agreement::SLUG},
                'title' => $agreement->{Agreement::TITLE},
                'version' => $agreement->{Agreement::VERSION},
                'effective_at' => $agreement->{Agreement::EFFECTIVE_AT}?->toDateString(),
                'checkboxes' => $agreement->{Agreement::CHECKBOXES} ?? [],
            ];
        });

        return success([
            'requires_acceptance' => $pending->isNotEmpty(),
            'agreements' => $pending,
        ], __('agreements.pending_list'));
    }

    /**
     * POST /profile/agreements/{slug}/accept — پذیرش فعال آخرین نسخه (re-acceptance)
     *
     * Body: terms_accepted=true (پذیرش یکجا) یا accepted_checkboxes=[CHK-01..]
     */
    public function accept(Request $request, string $slug, SignAgreementAction $action): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        // فقط سندهای مرتبط با خود کاربر قابل پذیرش‌اند — پذیرش سند نقشی که
        // کاربر ندارد بی‌معناست و ردپای حقوقی گمراه‌کننده می‌سازد.
        if (! in_array($slug, $this->relevantSlugsFor($user), true)) {
            return error(__('agreements.not_relevant'), [], 422);
        }

        $agreement = Agreement::latestActiveForSlug($slug);

        if (! $agreement) {
            return error(__('agreements.not_found'), [], 404);
        }

        $action->accept($user, $agreement, $request, 're_acceptance');

        return success([
            'slug' => $agreement->{Agreement::SLUG},
            'version' => $agreement->{Agreement::VERSION},
            'accepted' => true,
        ], __('agreements.accepted'));
    }

    /**
     * GET /profile/agreements/{slug}/pdf — PDF نسخه‌ای که «همین کاربر پذیرفته»
     *
     * الزام بند ۹ دستورالعمل: از پنل، همان نسخه‌ی پذیرفته‌شده ارائه شود، نه آخرین
     * نسخه‌ی عمومی. اگر چند نسخه پذیرفته باشد، جدیدترین پذیرشِ همان اسلاگ.
     */
    public function acceptedPdf(string $slug): Response|JsonResponse
    {
        /** @var Agreement|null $agreement */
        $agreement = Auth::user()->agreements()
            ->where(Agreement::SLUG, $slug)
            ->orderByDesc('user_agreements.accepted_at')
            ->first();

        if (! $agreement) {
            return error(__('agreements.not_accepted_yet'), [], 404);
        }

        return $this->renderPdf($agreement, $agreement->pivot);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * اسلاگ‌های مرتبط با کاربر: دو سند عمومی برای همه + سند اختصاصی هر نقشی
     * که دارد (الیت یار برای engineer/contractor).
     *
     * @return string[]
     */
    private function relevantSlugsFor(User $user): array
    {
        $slugs = [Agreement::SLUG_ELITEZONE_TERMS, Agreement::SLUG_PRIVACY_POLICY];

        if ($user->hasRole('engineer') || $user->hasRole('contractor')) {
            $slugs[] = Agreement::SLUG_ELITE_YAR_TERMS;
        }
        if ($user->hasRole('employer')) {
            $slugs[] = Agreement::SLUG_EMPLOYER_TERMS;
        }
        if ($user->hasRole('supplier')) {
            $slugs[] = Agreement::SLUG_SUPPLIER_TERMS;
        }

        return $slugs;
    }

    /**
     * رندر PDF سند (پرتره A4، راست‌به‌چپ). وقتی pivot پذیرش داده شود، بلوک
     * «رسید پذیرش» (نسخه/هش/زمان/روش) هم به انتهای سند اضافه می‌شود.
     */
    private function renderPdf(Agreement $agreement, mixed $acceptancePivot = null): Response
    {
        $pdf = PDF::loadView('pdf.agreement', [
            'agreement' => $agreement,
            'acceptance' => $acceptancePivot,
        ], [], [
            'mode' => 'utf-8',
            'format' => 'A4',
            'directionality' => 'rtl',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 14,
            'margin_bottom' => 14,
        ]);

        $filename = "agreement-{$agreement->{Agreement::SLUG}}-v{$agreement->{Agreement::VERSION}}.pdf";

        // LaravelMpdf::download() خروجی mPDF را مستقیم echo می‌کند و null برمی‌گرداند —
        // پاسخ HTTP استاندارد را خودمان از output() می‌سازیم.
        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
