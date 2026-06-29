<?php

namespace App\Services;

use App\DataTransferObjects\Ticket\TicketReplyDTO;
use App\Models\TicketFile;
use App\Models\TicketReply;
use App\Repositories\Contracts\TicketReplyRepositoryInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class TicketReplyService
{
    public function __construct(protected TicketReplyRepositoryInterface $repository) {}

    public function create(TicketReplyDTO $dto, int $ticketId): TicketReply
    {
        $data = $dto->toArray();
        $data['user_id']  = Auth::id();
        $data['ticket_id'] = $ticketId;

        /** @var TicketReply $reply */
        $reply = $this->repository->create($data);

        foreach ($dto->files ?? [] as $file) {
            $path = $file->store('ticket-files', 'public');
            $reply->files()->create([
                TicketFile::PATH          => $path,
                TicketFile::ORIGINAL_NAME => $file->getClientOriginalName(),
                TicketFile::MIME_TYPE     => $file->getMimeType() ?? $file->getClientMimeType(),
                TicketFile::SIZE          => $file->getSize(),
            ]);
        }

        return $reply;
    }
}
