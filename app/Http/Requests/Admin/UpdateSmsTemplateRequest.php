<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesSmsTemplate;
use App\Http\Requests\Concerns\ReadsScalarInput;
use App\Models\SmsTemplate;
use App\Sms\EventCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * ویرایشِ قالب. built-in فقط متن/فعال‌بودن/شرط می‌گیرد — عنوان و مخاطب و
 * کلیدش فراداده‌ی seeder است و از بدنه‌ی درخواست **نادیده** گرفته می‌شود
 * (نه ۴۲۲: فرم پنل یک شکل دارد و همه‌ی فیلدها را می‌فرستد). قالبِ سفارشی
 * عنوان/رویداد/مخاطب را هم می‌پذیرد.
 *
 * ⚠️ نامِ پارامترِ روت `{smsTemplate}` است — `$this->route('smsTemplate')`؛ نامِ
 * اشتباه بی‌صدا null می‌دهد و همه‌ی گاردهای زیر خاموش می‌شوند (تله‌ی MenuItem).
 */
class UpdateSmsTemplateRequest extends FormRequest
{
    use ReadsScalarInput;
    use ValidatesSmsTemplate;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $template = $this->template();
        $event = $this->scalarInput(SmsTemplate::EVENT, $template !== null ? (string) $template->{SmsTemplate::EVENT} : '');

        $rules = [
            SmsTemplate::BODY => ['sometimes', 'required', 'string', 'max:1000'],
            SmsTemplate::IS_ACTIVE => ['nullable', 'boolean'],
            ...$this->conditionRules(),
        ];

        if ($template?->{SmsTemplate::IS_CUSTOM}) {
            $rules[SmsTemplate::TITLE] = ['sometimes', 'required', 'string', 'max:150'];
            $rules[SmsTemplate::EVENT] = ['sometimes', 'required', 'string', Rule::in(EventCatalog::events())];
            $rules[SmsTemplate::AUDIENCE] = ['sometimes', 'required', 'string', Rule::in(EventCatalog::audiencesFor($event))];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $template = $this->template();

            if ($template === null) {
                return;
            }

            // رویدادِ تازه‌ی یک قالبِ سفارشی، جانگهدارهای خودش را می‌آورد.
            $event = $this->scalarInput(SmsTemplate::EVENT, '');
            $allowed = $template->{SmsTemplate::IS_CUSTOM} && $event !== ''
                ? (EventCatalog::all()[$event]['placeholders'] ?? [])
                : $template->allowedPlaceholders();

            $this->assertPlaceholdersKnown($v, $this->input(SmsTemplate::BODY), $allowed);
            $this->assertProtectedUntouched($v, (bool) $template->{SmsTemplate::IS_PROTECTED});
            $this->assertQuietHoursWindow($v);
        });
    }

    /**
     * فقط فیلدهایی که این قالب حق دارد بگیرد — built-in هرگز عنوان/رویداد/مخاطب نمی‌گیرد.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if (array_key_exists(SmsTemplate::CONDITIONS, $data)) {
            $data[SmsTemplate::CONDITIONS] = SmsTemplate::normalizeConditions($data[SmsTemplate::CONDITIONS]);
        }

        if (! $this->template()?->{SmsTemplate::IS_CUSTOM}) {
            unset($data[SmsTemplate::TITLE], $data[SmsTemplate::EVENT], $data[SmsTemplate::AUDIENCE]);
        }

        return $data;
    }

    public function messages(): array
    {
        return [
            'event.in' => __('admin.sms.event_unknown'),
            'audience.in' => __('admin.sms.audience_invalid'),
            ...$this->sharedMessages(),
        ];
    }

    private function template(): ?SmsTemplate
    {
        $template = $this->route('smsTemplate');

        return $template instanceof SmsTemplate ? $template : null;
    }
}
