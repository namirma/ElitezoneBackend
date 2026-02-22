<?php

namespace App\DataTransferObjects;

use Illuminate\Http\Request;

class TicketDTO extends BaseDTO
{
    const fields = [
        'user_id',
        'department_id',
        'subject',
        'message',
        'file'
    ];

    public static function fromRequest(Request $request): self
    {
        return self::validateAndMake($request, [
            'department_id' => ['required', 'exists:departments,id'],
            'subject' => ['required', 'string'],
            'message' => ['required', 'string'],
            'file.*' => ['nullable', 'file', 'max:5000'],
        ]);
    }

}
