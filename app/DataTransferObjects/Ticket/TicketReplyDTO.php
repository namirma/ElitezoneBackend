<?php

namespace App\DataTransferObjects\Ticket;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

final readonly class TicketReplyDTO
{
    public function __construct(
        public readonly string $message,
        public readonly ?int $user_id = null,
        public readonly ?int $admin_id = null,
        public readonly ?int $ticket_id = null,
        /** @var UploadedFile[]|null */
        public readonly ?array $files = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $request->validate([
            'message' => ['required', 'string'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'mimes:jpeg,jpg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,zip', 'max:10240'],
        ]);

        return new self(
            message: (string) $request->input('message'),
            files: $request->file('files'),
        );
    }

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
