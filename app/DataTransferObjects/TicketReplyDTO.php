<?php

namespace App\DataTransferObjects;

use Illuminate\Http\Request;

class TicketReplyDTO extends BaseDTO
{
    const fields = [
        'ticket_id',
        'user_id',
        'admin_id',
        'message',
        'file'
    ];

    public static function fromRequest(Request $request): self
    {
        return self::validateAndMake($request, [
            'message' => ['required', 'string'],
            'file' => ['nullable', 'array'],
            'file.*' => ['nullable'],
        ]);
    }

}
