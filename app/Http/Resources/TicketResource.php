<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
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
