<?php

namespace App\Http\Resources\Ticket;

use App\Http\Resources\BaseJsonResource;
use App\Models\TicketFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin TicketFile
 */
class TicketFileResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'url' => Storage::disk('public')->url($this->path),
            'size' => $this->size,
            'mime_type' => $this->mime_type,
        ];
    }
}
