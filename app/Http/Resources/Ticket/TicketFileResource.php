<?php

namespace App\Http\Resources\Ticket;

use Illuminate\Http\Request;
use App\Http\Resources\BaseJsonResource;

class TicketFileResource extends BaseJsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
