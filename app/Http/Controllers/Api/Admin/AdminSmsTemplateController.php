<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSmsTemplateRequest;
use App\Http\Requests\Admin\TestSmsTemplateRequest;
use App\Http\Requests\Admin\UpdateSmsTemplateRequest;
use App\Http\Resources\Content\SmsTemplateResource;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Services\SmsService;
use App\Sms\EventCatalog;
use App\Sms\PlaceholderCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * پنل مدیریت — پیامک‌های سیستم: متن، شرط، فعال/غیرفعال، قالبِ سفارشی روی رویداد.
 *
 * ⚠️ متنِ این ردیف‌ها مستقیم به گوشیِ مشتری می‌رود؛ هر تغییر با activity() ثبت می‌شود.
 */
class AdminSmsTemplateController extends Controller
{
    // GET /admin/sms-templates
    public function index(Request $request): JsonResponse
    {
        // کلیدی که نشستِ دیگری همین امروز به lang اضافه کرده نباید از فهرست جا بماند.
        SmsTemplate::syncFromLang();

        $query = SmsTemplate::query()
            ->orderBy(SmsTemplate::IS_CUSTOM)
            ->orderBy(SmsTemplate::ID);

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->trim().'%';
            $query->where(fn ($q) => $q
                ->where(SmsTemplate::TITLE, 'like', $term)
                ->orWhere(SmsTemplate::KEY, 'like', $term)
                ->orWhere(SmsTemplate::BODY, 'like', $term));
        }

        if ($request->has('is_active') && $request->input('is_active') !== '') {
            $query->where(SmsTemplate::IS_ACTIVE, $request->boolean('is_active'));
        }

        if ($request->filled('audience')) {
            $query->where(SmsTemplate::AUDIENCE, $request->string('audience'));
        }

        if ($request->has('is_custom') && $request->input('is_custom') !== '') {
            $query->where(SmsTemplate::IS_CUSTOM, $request->boolean('is_custom'));
        }

        return success(SmsTemplateResource::collection($query->paginate($this->perPage(50))), __('admin.sms.list_received'));
    }

    // GET /admin/sms-templates/events
    public function events(): JsonResponse
    {
        // قالب‌هایی که همین حالا روی هر رویداد سوارند — built-in و سفارشی، هر دو
        $usedBy = SmsTemplate::query()
            ->whereNotNull(SmsTemplate::EVENT)
            ->orderBy(SmsTemplate::IS_CUSTOM)
            ->orderBy(SmsTemplate::ID)
            ->get([SmsTemplate::ID, SmsTemplate::KEY, SmsTemplate::TITLE, SmsTemplate::EVENT, SmsTemplate::AUDIENCE, SmsTemplate::IS_ACTIVE, SmsTemplate::IS_CUSTOM])
            ->groupBy(SmsTemplate::EVENT);

        $events = [];

        foreach (EventCatalog::all() as $class => $meta) {
            $events[] = [
                'event' => $class,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'audiences' => collect($meta['audiences'])
                    ->map(fn ($label, $value) => ['value' => $value, 'label' => $label, 'resolution' => $meta['resolutions'][$value] ?? null])
                    ->values()
                    ->all(),
                'placeholders' => PlaceholderCatalog::describe($meta['placeholders']),
                'templates' => ($usedBy[$class] ?? collect())
                    ->map(fn (SmsTemplate $t) => [
                        SmsTemplate::ID => $t->id,
                        SmsTemplate::KEY => $t->{SmsTemplate::KEY},
                        SmsTemplate::TITLE => $t->{SmsTemplate::TITLE},
                        SmsTemplate::AUDIENCE => $t->{SmsTemplate::AUDIENCE},
                        SmsTemplate::IS_ACTIVE => (bool) $t->{SmsTemplate::IS_ACTIVE},
                        SmsTemplate::IS_CUSTOM => (bool) $t->{SmsTemplate::IS_CUSTOM},
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return success($events, __('admin.sms.events_received'));
    }

    // GET /admin/sms-templates/{smsTemplate}
    public function show(SmsTemplate $smsTemplate): JsonResponse
    {
        return success(new SmsTemplateResource($smsTemplate), __('admin.sms.received'));
    }

    // POST /admin/sms-templates — فقط قالبِ سفارشی
    public function store(StoreSmsTemplateRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data[SmsTemplate::CONDITIONS] = SmsTemplate::normalizeConditions($data[SmsTemplate::CONDITIONS] ?? null);

        $template = SmsTemplate::create([
            ...$data,
            SmsTemplate::KEY => $this->freshCustomKey(),
            SmsTemplate::IS_CUSTOM => true,
            SmsTemplate::IS_ACTIVE => $data[SmsTemplate::IS_ACTIVE] ?? true,
            SmsTemplate::PLACEHOLDERS => EventCatalog::all()[$data[SmsTemplate::EVENT]]['placeholders'] ?? [],
            SmsTemplate::TRIGGER_DESCRIPTION => __('admin.sms.custom_trigger', [
                'event' => EventCatalog::all()[$data[SmsTemplate::EVENT]]['label'] ?? $data[SmsTemplate::EVENT],
                'audience' => EventCatalog::all()[$data[SmsTemplate::EVENT]]['audiences'][$data[SmsTemplate::AUDIENCE]] ?? $data[SmsTemplate::AUDIENCE],
            ]),
        ]);

        activity()->performedOn($template)->causedBy(auth()->user())
            ->withProperties([SmsTemplate::KEY => $template->{SmsTemplate::KEY}, SmsTemplate::EVENT => $template->{SmsTemplate::EVENT}, SmsTemplate::AUDIENCE => $template->{SmsTemplate::AUDIENCE}])
            ->log('پیامک سفارشی ساخته شد.');

        return created(new SmsTemplateResource($template), __('admin.sms.created'));
    }

    // PUT /admin/sms-templates/{smsTemplate}
    public function update(UpdateSmsTemplateRequest $request, SmsTemplate $smsTemplate): JsonResponse
    {
        $data = $request->payload();

        if ($smsTemplate->{SmsTemplate::IS_CUSTOM} && isset($data[SmsTemplate::EVENT])) {
            $data[SmsTemplate::PLACEHOLDERS] = EventCatalog::all()[$data[SmsTemplate::EVENT]]['placeholders'] ?? [];
        }

        $smsTemplate->update($data);

        activity()->performedOn($smsTemplate)->causedBy(auth()->user())
            ->withProperties([SmsTemplate::KEY => $smsTemplate->{SmsTemplate::KEY}, ...$data])
            ->log('پیامک ویرایش شد.');

        return success(new SmsTemplateResource($smsTemplate->refresh()), __('admin.sms.updated'));
    }

    // POST /admin/sms-templates/{smsTemplate}/toggle
    public function toggle(SmsTemplate $smsTemplate): JsonResponse
    {
        if ($smsTemplate->{SmsTemplate::IS_PROTECTED} && $smsTemplate->{SmsTemplate::IS_ACTIVE}) {
            return error(__('admin.sms.protected_cannot_deactivate'), [], 422);
        }

        $smsTemplate->update([SmsTemplate::IS_ACTIVE => ! $smsTemplate->{SmsTemplate::IS_ACTIVE}]);

        activity()->performedOn($smsTemplate)->causedBy(auth()->user())
            ->withProperties([SmsTemplate::KEY => $smsTemplate->{SmsTemplate::KEY}, SmsTemplate::IS_ACTIVE => $smsTemplate->{SmsTemplate::IS_ACTIVE}])
            ->log('وضعیت پیامک تغییر کرد.');

        return success(new SmsTemplateResource($smsTemplate->refresh()), __('admin.sms.toggled'));
    }

    // DELETE /admin/sms-templates/{smsTemplate} — فقط سفارشی؛ built-in با toggle خاموش می‌شود
    public function destroy(SmsTemplate $smsTemplate): JsonResponse
    {
        if (! $smsTemplate->{SmsTemplate::IS_CUSTOM}) {
            return error(__('admin.sms.builtin_cannot_delete'), [], 422);
        }

        activity()->causedBy(auth()->user())
            ->withProperties([SmsTemplate::KEY => $smsTemplate->{SmsTemplate::KEY}])
            ->log('پیامک سفارشی حذف شد.');

        $smsTemplate->delete();

        return success(null, __('admin.sms.deleted'));
    }

    /**
     * POST /admin/sms-templates/{smsTemplate}/test
     *
     * همان مسیرِ واقعی (`SmsService::send` → allowlist → درگاه)، ولی همزمان و
     * بدونِ صف/dedupe، تا نتیجه‌ی **واقعی** به ادمین برگردد — نه «در صف قرار
     * گرفت». خاموش‌بودن و شرط‌ها عمداً اعمال نمی‌شوند: آزمایشِ متن است، نه رویداد.
     */
    public function test(TestSmsTemplateRequest $request, SmsTemplate $smsTemplate, SmsService $sms): JsonResponse
    {
        $key = (string) $smsTemplate->{SmsTemplate::KEY};
        $params = [];

        foreach ($smsTemplate->allowedPlaceholders() as $placeholder) {
            $params[$placeholder] = '«'.$placeholder.'»';
        }

        foreach ((array) $request->validated('params', []) as $name => $value) {
            if (is_string($value) && $value !== '') {
                $params[(string) $name] = $value;
            }
        }

        $mobile = (string) $request->validated('mobile');
        $body = (string) SmsTemplate::text($key, $params);

        $sms->send($mobile, $body, $key);

        // نتیجه از لاگ خوانده می‌شود — همان چیزی که تبِ «گزارش ارسال» نشان می‌دهد.
        $log = SmsLog::query()
            ->where(SmsLog::MOBILE, $mobile)
            ->where(SmsLog::TEMPLATE_KEY, $key)
            ->latest(SmsLog::ID)
            ->first();

        $status = $log instanceof SmsLog ? (string) $log->{SmsLog::STATUS} : SmsLog::STATUS_FAILED;

        // کلیدِ لیترال به‌ازای هر وضعیت — `TranslationKeySweepTest` کلیدِ ساخته‌شده با نقطه را نمی‌بیند.
        $message = match ($status) {
            SmsLog::STATUS_SENT => __('admin.sms.test_sent'),
            SmsLog::STATUS_SKIPPED_ALLOWLIST => __('admin.sms.test_skipped_allowlist'),
            SmsLog::STATUS_SKIPPED_INACTIVE => __('admin.sms.test_skipped_inactive'),
            SmsLog::STATUS_SKIPPED_CONDITION => __('admin.sms.test_skipped_condition'),
            SmsLog::STATUS_UNCONFIGURED => __('admin.sms.test_unconfigured'),
            default => __('admin.sms.test_failed'),
        };

        return success(
            ['status' => $status, 'mobile' => $mobile, 'body' => $body, 'error' => $log instanceof SmsLog ? $log->{SmsLog::ERROR} : null],
            $message,
        );
    }

    private function freshCustomKey(): string
    {
        do {
            $key = 'custom_'.Str::lower(Str::random(10));
        } while (SmsTemplate::query()->where(SmsTemplate::KEY, $key)->exists());

        return $key;
    }
}
