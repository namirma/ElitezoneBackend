<?php

namespace App\Http\Requests\Content;

use App\Models\PromoOverlay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * اعتبارسنجیِ ساخت/ویرایشِ اورلی.
 *
 * واژگانِ اکشن و شرط عمداً بسته است: هر مقداری که فرانت نتواند به رفتارِ مشخصی
 * نگاشت کند، باید همین‌جا رد شود — وگرنه ادمین دکمه‌ای می‌سازد که در سایت
 * بی‌صدا هیچ کاری نمی‌کند.
 */
class StorePromoOverlayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // مسیر پشتِ auth:sanctum + role.admin + admin.permission است
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH')
            || $this->routeIs('*.update');

        return [
            'title' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:2000'],

            // تصویر: svg ممنوع — روی دیسکِ public سرو می‌شود و SVGِ حاوی
            // <script> یعنی XSSِ ذخیره‌شده (قاعده‌ی سراسریِ این ریپو).
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_image' => ['nullable', 'boolean'],

            // ── دکمه‌ها ──────────────────────────────────────────────────────
            'buttons' => ['nullable', 'array', 'max:4'],
            'buttons.*.label' => ['required_with:buttons', 'string', 'max:60'],
            'buttons.*.style' => ['required_with:buttons', Rule::in(PromoOverlay::BUTTON_STYLES)],
            'buttons.*.action' => ['required_with:buttons', Rule::in(PromoOverlay::BUTTON_ACTIONS)],
            'buttons.*.value' => ['nullable', 'string', 'max:255'],

            // ── شرطِ مخاطب ───────────────────────────────────────────────────
            'audience' => ['nullable', 'array'],
            'audience.op' => ['nullable', Rule::in(['and', 'or'])],
            'audience.rules' => ['nullable', 'array', 'max:20'],

            // ── مسیرها ───────────────────────────────────────────────────────
            'routes' => ['nullable', 'array'],
            'routes.include' => ['nullable', 'array', 'max:50'],
            'routes.include.*' => ['string', 'max:120'],
            'routes.exclude' => ['nullable', 'array', 'max:50'],
            'routes.exclude.*' => ['string', 'max:120'],

            // ── زمان‌بندی ────────────────────────────────────────────────────
            'delay_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'frequency' => ['nullable', Rule::in(PromoOverlay::FREQUENCIES)],
            'frequency_ttl_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],

            'priority' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => __('admin.promo_overlays.title_required'),
            'buttons.max' => __('admin.promo_overlays.too_many_buttons'),
            'buttons.*.action.in' => __('admin.promo_overlays.invalid_action'),
            'buttons.*.style.in' => __('admin.promo_overlays.invalid_style'),
            'frequency.in' => __('admin.promo_overlays.invalid_frequency'),
            'image.mimes' => __('admin.promo_overlays.image_mimes'),
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $this->validateButtonValues($v);
            $this->validateAudienceRules($v, $this->input('audience.rules', []), 'audience.rules');
            $this->validateTtl($v);
        });
    }

    /** هر اکشن، شکلِ `value`ِ خودش را دارد. */
    private function validateButtonValues($v): void
    {
        foreach ((array) $this->input('buttons', []) as $i => $btn) {
            $action = $btn['action'] ?? null;
            $value = trim((string) ($btn['value'] ?? ''));
            $path = "buttons.$i.value";

            if ($action === PromoOverlay::ACTION_NAVIGATE) {
                if ($value === '' || ! str_starts_with($value, '/')) {
                    $v->errors()->add($path, __('admin.promo_overlays.navigate_needs_path'));
                }
            }

            if ($action === PromoOverlay::ACTION_EXTERNAL) {
                if (! filter_var($value, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $value)) {
                    $v->errors()->add($path, __('admin.promo_overlays.external_needs_url'));
                }
            }

            if ($action === PromoOverlay::ACTION_MORE_INFO && $value === '') {
                $v->errors()->add($path, __('admin.promo_overlays.more_info_needs_key'));
            }
        }
    }

    /** درختِ شرط بازگشتی است؛ هر برگ باید نوعِ شناخته‌شده داشته باشد. */
    private function validateAudienceRules($v, array $rules, string $prefix, int $depth = 0): void
    {
        if ($depth > 3) {
            $v->errors()->add($prefix, __('admin.promo_overlays.audience_too_deep'));

            return;
        }

        foreach ($rules as $i => $rule) {
            $path = "$prefix.$i";

            if (! is_array($rule)) {
                $v->errors()->add($path, __('admin.promo_overlays.invalid_rule'));

                continue;
            }

            // گرهِ تودرتو
            if (isset($rule['rules'])) {
                if (! in_array(strtolower((string) ($rule['op'] ?? 'and')), ['and', 'or'], true)) {
                    $v->errors()->add("$path.op", __('admin.promo_overlays.invalid_op'));
                }
                $this->validateAudienceRules($v, (array) $rule['rules'], "$path.rules", $depth + 1);

                continue;
            }

            $type = $rule['type'] ?? null;
            if (! in_array($type, PromoOverlay::RULE_TYPES, true)) {
                $v->errors()->add("$path.type", __('admin.promo_overlays.invalid_rule_type'));

                continue;
            }

            $value = $rule['value'] ?? null;

            if ($type === PromoOverlay::RULE_AUTH_STATE
                && ! in_array($value, ['guest', 'authenticated'], true)) {
                $v->errors()->add("$path.value", __('admin.promo_overlays.invalid_auth_state'));
            }

            if ($type === PromoOverlay::RULE_PERSONA) {
                // PERSONAS_ACCEPTED (نه PERSONAS): نامِ قدیمیِ `expert` هنوز در
                // قاعده‌های ذخیره‌شده هست و ردش کردن یعنی ادمین نتواند اورلیِ
                // موجود را حتی برای عوض کردنِ یک متن ذخیره کند.
                $personas = (array) $value;
                if ($personas === [] || array_diff($personas, PromoOverlay::PERSONAS_ACCEPTED)) {
                    $v->errors()->add("$path.value", __('admin.promo_overlays.invalid_persona'));
                }
            }

            if ($type === PromoOverlay::RULE_ORDERS_COUNT) {
                if (! is_numeric($value)) {
                    $v->errors()->add("$path.value", __('admin.promo_overlays.rule_needs_number'));
                }
                if (! in_array($rule['operator'] ?? null, PromoOverlay::RULE_OPERATORS, true)) {
                    $v->errors()->add("$path.operator", __('admin.promo_overlays.invalid_operator'));
                }
            }
        }
    }

    /** `ttl` بدون ساعت یعنی دوره‌ی تکرارِ تعریف‌نشده. */
    private function validateTtl($v): void
    {
        if ($this->input('frequency') === PromoOverlay::FREQ_TTL
            && ! $this->filled('frequency_ttl_hours')) {
            $v->errors()->add('frequency_ttl_hours', __('admin.promo_overlays.ttl_hours_required'));
        }
    }
}
