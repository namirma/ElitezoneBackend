<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Models\SmsTemplate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * قواعدِ مشترکِ ساخت و ویرایشِ قالبِ پیامک — یک‌جا، تا دو FormRequest جدا نیفتند.
 *
 * - `conditions` فقط سه کلیدِ شناخته‌شده را می‌پذیرد (`array:` کلیدِ ناشناس را ۴۲۲ می‌کند).
 * - قالبِ حفاظت‌شده نه خاموش می‌شود نه شرط می‌گیرد — هر دو یعنی قطعِ ورود.
 * - هر `:placeholder` در متن باید در فهرستِ مجازِ همان قالب/رویداد باشد، وگرنه
 *   خام به گوشیِ مشتری می‌رسد.
 */
trait ValidatesSmsTemplate
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function conditionRules(): array
    {
        return [
            SmsTemplate::CONDITIONS => ['nullable', 'array:roles,min_amount,quiet_hours'],
            'conditions.roles' => ['nullable', 'array'],
            'conditions.roles.*' => ['string', Rule::in(SmsTemplate::ROLE_OPTIONS)],
            'conditions.min_amount' => ['nullable', 'integer', 'min:0'],
            'conditions.quiet_hours' => ['nullable', 'array:from,to'],
            'conditions.quiet_hours.from' => ['required_with:conditions.quiet_hours', 'date_format:H:i'],
            'conditions.quiet_hours.to' => ['required_with:conditions.quiet_hours', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function sharedMessages(): array
    {
        return [
            'title.required' => __('admin.sms.title_required'),
            'body.required' => __('admin.sms.body_required'),
            'body.max' => __('admin.sms.body_too_long'),
            'conditions.array' => __('admin.sms.conditions_invalid'),
            'conditions.roles.*.in' => __('admin.sms.role_invalid'),
            'conditions.min_amount.integer' => __('admin.sms.min_amount_invalid'),
            'conditions.min_amount.min' => __('admin.sms.min_amount_invalid'),
            'conditions.quiet_hours.array' => __('admin.sms.quiet_hours_invalid'),
            'conditions.quiet_hours.from.required_with' => __('admin.sms.quiet_hours_invalid'),
            'conditions.quiet_hours.from.date_format' => __('admin.sms.quiet_hours_invalid'),
            'conditions.quiet_hours.to.required_with' => __('admin.sms.quiet_hours_invalid'),
            'conditions.quiet_hours.to.date_format' => __('admin.sms.quiet_hours_invalid'),
        ];
    }

    /**
     * @param  list<string>  $allowedPlaceholders
     */
    protected function assertPlaceholdersKnown(Validator $validator, mixed $body, array $allowedPlaceholders): void
    {
        // `after` روی بدنه‌ای که قاعده‌ها را رد کرده هم اجرا می‌شود؛ بدنه‌ی
        // غیررشته‌ای (`{"body":[]}`) قبلاً TypeError → ۵۰۰ می‌داد. قاعده‌ی `string`
        // خودش ۴۲۲ داده است. پین: `Security/ValidationNeverFiveHundredTest`.
        if (! is_string($body)) {
            return;
        }

        $unknown = array_diff(SmsTemplate::placeholdersIn($body), $allowedPlaceholders);

        if ($unknown !== []) {
            $validator->errors()->add(
                SmsTemplate::BODY,
                __('admin.sms.placeholder_unknown', ['placeholders' => implode('، ', array_map(fn ($p) => ':'.$p, $unknown))]),
            );
        }
    }

    protected function assertProtectedUntouched(Validator $validator, bool $isProtected): void
    {
        if (! $isProtected) {
            return;
        }

        if ($this->has(SmsTemplate::IS_ACTIVE) && ! $this->boolean(SmsTemplate::IS_ACTIVE)) {
            $validator->errors()->add(SmsTemplate::IS_ACTIVE, __('admin.sms.protected_cannot_deactivate'));
        }

        if (SmsTemplate::normalizeConditions($this->input(SmsTemplate::CONDITIONS)) !== null) {
            $validator->errors()->add(SmsTemplate::CONDITIONS, __('admin.sms.protected_no_conditions'));
        }
    }

    protected function assertQuietHoursWindow(Validator $validator): void
    {
        $window = $this->input('conditions.quiet_hours');

        if (is_array($window) && isset($window['from'], $window['to']) && $window['from'] === $window['to']) {
            $validator->errors()->add('conditions.quiet_hours', __('admin.sms.quiet_hours_invalid'));
        }
    }
}
