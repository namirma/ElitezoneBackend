<?php

namespace App\Http\Resources\Content;

use App\Http\Resources\BaseJsonResource;
use App\Models\SmsLog;
use Illuminate\Http\Request;

/**
 * @mixin SmsLog
 */
class SmsLogResource extends BaseJsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            SmsLog::ID => $this->id,
            SmsLog::MOBILE => $this->{SmsLog::MOBILE},
            SmsLog::TEMPLATE_KEY => $this->{SmsLog::TEMPLATE_KEY},
            SmsLog::BODY => $this->{SmsLog::BODY},
            SmsLog::STATUS => $this->{SmsLog::STATUS},
            SmsLog::ERROR => $this->{SmsLog::ERROR},
            SmsLog::CREATED_AT => $this->created_at,
            'created_at_p' => $this->created_at_p,
        ];
    }
}
