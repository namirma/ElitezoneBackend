<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesSmsTemplate;
use App\Models\SmsTemplate;
use App\Sms\EventCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * ساختِ پیامکِ سفارشی — فقط روی رویدادِ فهرست‌شده (`EventCatalog`) و مخاطبی که آن
 * رویداد می‌شناسد. کلیدِ ردیف را سرور می‌سازد؛ built-inها از seeder می‌آیند، نه از این‌جا.
 */
class StoreSmsTemplateRequest extends FormRequest
{
    use ValidatesSmsTemplate;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $event = (string) $this->input(SmsTemplate::EVENT, '');

        return [
            SmsTemplate::TITLE => ['required', 'string', 'max:150'],
            SmsTemplate::EVENT => ['required', 'string', Rule::in(EventCatalog::events())],
            SmsTemplate::AUDIENCE => ['required', 'string', Rule::in(EventCatalog::audiencesFor($event))],
            SmsTemplate::BODY => ['required', 'string', 'max:1000'],
            SmsTemplate::IS_ACTIVE => ['nullable', 'boolean'],
            ...$this->conditionRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $event = (string) $this->input(SmsTemplate::EVENT, '');
            $allowed = EventCatalog::all()[$event]['placeholders'] ?? [];

            $this->assertPlaceholdersKnown($v, $this->input(SmsTemplate::BODY), $allowed);
            $this->assertQuietHoursWindow($v);
        });
    }

    public function messages(): array
    {
        return [
            'event.required' => __('admin.sms.event_unknown'),
            'event.in' => __('admin.sms.event_unknown'),
            'audience.required' => __('admin.sms.audience_invalid'),
            'audience.in' => __('admin.sms.audience_invalid'),
            ...$this->sharedMessages(),
        ];
    }
}
