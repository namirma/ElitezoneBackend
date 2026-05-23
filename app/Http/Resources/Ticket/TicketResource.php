<?php

namespace App\Http\Resources\Ticket;

use Illuminate\Http\Request;
use App\Http\Resources\BaseJsonResource;

/**
 * @mixin \App\Models\Ticket
 */
class TicketResource extends BaseJsonResource
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
            'code' => $this->code,
            'subject' => $this->subject,
            'message' => $this->message,
            'status' => $this->status,
            'created_at' => $this->created_at?->toDateTimeString(),
            'user' => $this->user,
            //            'admin' => new UserTicketResource($this->whenLoaded('admin')),
            'department' => new DepartmentResource($this->whenLoaded('department')),
            'attachments' => TicketFileResource::collection($this->whenLoaded('files')),
            'replies' => TicketReplyResource::collection($this->whenLoaded('replies')),
        ];
    }
}
