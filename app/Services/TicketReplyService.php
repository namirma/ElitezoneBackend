<?php

namespace App\Services;

use App\Contracts\TicketReplyRepositoryInterface;
use App\DataTransferObjects\TicketReplyDTO;
use Illuminate\Support\Facades\Auth;

class TicketReplyService
{
    public function __construct(protected TicketReplyRepositoryInterface $repository)
    {
    }

    public function create(TicketReplyDTO $dto, $ticketId)
    {
        $dto->user_id = Auth::id();
        $dto->ticket_id = $ticketId;

        return $this->repository->create($dto->toArray());
    }

}
