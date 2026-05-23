<?php

namespace App\Http\Resources\Ticket;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketReplyResource extends JsonResource
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
