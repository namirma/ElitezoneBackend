<?php

namespace App\Services;

use App\DataTransferObjects\TicketReplyDTO;
use App\Repositories\Contracts\TicketReplyRepositoryInterface;
use Illuminate\Support\Facades\Auth;

class TicketReplyService
{
    public function __construct(protected TicketReplyRepositoryInterface $repository) {}

    public function create(TicketReplyDTO $dto, int $ticketId)
    {
        $data = $dto->toArray();
        $data['user_id'] = Auth::id();
        $data['ticket_id'] = $ticketId;

        return $this->repository->create($data);
    }
}
