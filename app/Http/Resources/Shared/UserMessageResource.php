<?php

namespace App\Http\Resources\Shared;

use App\Models\UserNotification;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Resources\BaseJsonResource;

class UserMessageResource extends BaseJsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $readAt = $this->read_at ? Carbon::parse($this->read_at) : null;
        $createdAt = $this->created_at ? Carbon::parse($this->created_at) : null;

        return [
            UserNotification::ID => $this->id,
            UserNotification::SUBJECT => $this->subject,
            UserNotification::BODY => $this->body,
            UserNotification::READ_AT => $readAt ? $readAt->format('Y-m-d H:i:s') : null,
            UserNotification::CREATED_AT => $createdAt ? $createdAt->format('Y-m-d H:i:s') : null,

            'read_at_p' => $readAt ? verta($readAt)->format('Y/m/d H:i') : null,
            'created_at_p' => $createdAt ? verta($createdAt)->format('Y/m/d H:i') : null,

            'read_at_diff' => $readAt ? verta($readAt)->formatDifference() : null,
            'created_at_diff' => $createdAt ? verta($createdAt)->formatDifference() : null,
        ];
    }
}
