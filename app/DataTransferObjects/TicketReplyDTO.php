<?php

namespace App\DataTransferObjects;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Data transfer object for creating a ticket reply.
 *
 * Uses the readonly constructor pattern (PHP 8.1) for consistency with newer DTOs.
 */
final class TicketReplyDTO
{
    public function __construct(
        public readonly string $message,
        public readonly ?int $user_id = null,
        public readonly ?int $admin_id = null,
        public readonly ?int $ticket_id = null,
        /** @var UploadedFile[]|null */
        public readonly ?array $file = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $request->validate([
            'message' => ['required', 'string'],
            'file' => ['nullable', 'array'],
            'file.*' => ['nullable'],
        ]);

        return new self(
            message: (string) $request->input('message'),
            file: $request->file('file'),
        );
    }

    /** Converts to array for repository::create() */
    public function toArray(): array
    {
        $data = ['message' => $this->message];

        if ($this->user_id !== null) {
            $data['user_id'] = $this->user_id;
        }
        if ($this->admin_id !== null) {
            $data['admin_id'] = $this->admin_id;
        }
        if ($this->ticket_id !== null) {
            $data['ticket_id'] = $this->ticket_id;
        }

        return $data;
    }
}
