<?php

namespace App\Http\Resources\Ticket;

use Illuminate\Http\Request;
use App\Http\Resources\BaseJsonResource;

class TicketReplyResource extends BaseJsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'message' => $this->message,
            'user' => $this->user,
            'admin' => $this->admin,
            //            'attachments' => TicketFileResource::collection($this->files),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
