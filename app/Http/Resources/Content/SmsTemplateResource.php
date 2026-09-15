<?php

namespace App\Http\Resources\Content;

use App\Http\Resources\BaseJsonResource;
use App\Models\SmsTemplate;
use App\Sms\EventCatalog;
use Illuminate\Http\Request;

/**
 * @mixin SmsTemplate
 */
class SmsTemplateResource extends BaseJsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $event = $this->{SmsTemplate::EVENT};

        return [
            SmsTemplate::ID => $this->id,
            SmsTemplate::KEY => $this->{SmsTemplate::KEY},
            SmsTemplate::TITLE => $this->{SmsTemplate::TITLE},
            SmsTemplate::TRIGGER_DESCRIPTION => $this->{SmsTemplate::TRIGGER_DESCRIPTION},
            SmsTemplate::AUDIENCE => $this->{SmsTemplate::AUDIENCE},
            SmsTemplate::BODY => $this->{SmsTemplate::BODY},
            // [{name, meaning, example}] — نه فهرستِ خامِ نام‌ها؛ ادمین باید بداند چه چیزی جایش می‌نشیند
            SmsTemplate::PLACEHOLDERS => $this->resource->placeholderDetails(),
            SmsTemplate::IS_ACTIVE => (bool) $this->{SmsTemplate::IS_ACTIVE},
            SmsTemplate::IS_PROTECTED => (bool) $this->{SmsTemplate::IS_PROTECTED},
            SmsTemplate::CONDITIONS => $this->{SmsTemplate::CONDITIONS},
            SmsTemplate::EVENT => $event,
            'event_label' => $event !== null ? (EventCatalog::all()[$event]['label'] ?? $event) : null,
            'event_description' => $event !== null ? (EventCatalog::all()[$event]['description'] ?? null) : null,
            // قالبِ سفارشی همیشه رویدادی است؛ ستونش را seeder پر نمی‌کند
            SmsTemplate::TRIGGER_KIND => $this->{SmsTemplate::TRIGGER_KIND} ?? ($this->{SmsTemplate::IS_CUSTOM} ? SmsTemplate::KIND_EVENT : null),
            SmsTemplate::SENDER => $this->{SmsTemplate::SENDER},
            SmsTemplate::SCHEDULE => $this->{SmsTemplate::SCHEDULE},
            'audience_resolution' => $event !== null ? (EventCatalog::all()[$event]['resolutions'][$this->{SmsTemplate::AUDIENCE}] ?? null) : null,
            SmsTemplate::IS_CUSTOM => (bool) $this->{SmsTemplate::IS_CUSTOM},
            SmsTemplate::UPDATED_AT => $this->updated_at,
        ];
    }
}
