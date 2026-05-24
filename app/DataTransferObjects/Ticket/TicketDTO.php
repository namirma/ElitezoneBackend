<?php

namespace App\DataTransferObjects\Ticket;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Data transfer object for creating a new ticket.
 *
 * Uses the readonly constructor pattern (PHP 8.1) — the standard DTO style
 * across the codebase.
 */
final class TicketDTO
{
    public function __construct(
        public readonly int $department_id,
        public readonly string $subject,
        public readonly string $message,
        public readonly ?int $user_id = null,
        /** @var UploadedFile[]|null */
        public readonly ?array $file = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $request->validate([
            'department_id' => ['required', 'exists:departments,id'],
            'subject' => ['required', 'string'],
            'message' => ['required', 'string'],
            'file.*' => ['nullable', 'file', 'max:5000'],
        ]);

        return new self(
            department_id: (int) $request->input('department_id'),
            subject: (string) $request->input('subject'),
            message: (string) $request->input('message'),
            file: $request->file('file'),
        );
    }

    /** Converts to array for repository::create() */
    public function toArray(): array
    {
        $data = [
            'department_id' => $this->department_id,
            'subject' => $this->subject,
            'message' => $this->message,
        ];

        if ($this->user_id !== null) {
            $data['user_id'] = $this->user_id;
        }

        return $data;
    }
}
