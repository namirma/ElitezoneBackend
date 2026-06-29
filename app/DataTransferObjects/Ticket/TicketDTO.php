<?php

namespace App\DataTransferObjects\Ticket;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

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
        public readonly string $priority = Ticket::PRIORITY_NORMAL,
        public readonly ?int $order_id = null,
        public readonly ?int $province_id = null,
        public readonly ?int $city_id = null,
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
            'priority' => ['nullable', Rule::in(Ticket::PRIORITIES)],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'province_id' => ['nullable', 'integer'],
            'city_id' => ['nullable', 'integer'],
            'file.*' => ['nullable', 'file', 'max:5000'],
        ]);

        return new self(
            department_id: (int) $request->input('department_id'),
            subject: (string) $request->input('subject'),
            message: (string) $request->input('message'),
            priority: (string) $request->input('priority', Ticket::PRIORITY_NORMAL),
            order_id: $request->filled('order_id') ? (int) $request->input('order_id') : null,
            province_id: $request->filled('province_id') ? (int) $request->input('province_id') : null,
            city_id: $request->filled('city_id') ? (int) $request->input('city_id') : null,
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
            'priority' => $this->priority,
            'order_id' => $this->order_id,
            'province_id' => $this->province_id,
            'city_id' => $this->city_id,
        ];

        if ($this->user_id !== null) {
            $data['user_id'] = $this->user_id;
        }

        return $data;
    }
}
