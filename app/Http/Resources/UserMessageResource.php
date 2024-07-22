<?php

namespace App\Http\Resources;

use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserMessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            UserNotification::ID => $this->id,
            UserNotification::SUBJECT => $this->subject,
            UserNotification::BODY => $this->body,
            UserNotification::READ_AT => $this->read_at,
            UserNotification::CREATED_AT => $this->created_at_,
            'created_at_p' => $this->created_at_p,
            'created_at_diff' => $this->created_at_diff,
        ];
    }
}
