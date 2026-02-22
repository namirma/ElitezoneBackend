<?php

namespace App\Services;

use App\Contracts\TicketRepositoryInterface;
use App\DataTransferObjects\TicketDTO;
use App\Models\Ticket;
use Illuminate\Support\Facades\Auth;

class TicketService
{
    public function __construct(protected TicketRepositoryInterface $repository)
    {
    }

    public function list()
    {
        return $this->repository->list();
    }

    public function create(TicketDTO $dto)
    {
        $dto->user_id = Auth::id();

        return $this->repository->create($dto->toArray());
    }

    public function getDepartments()
    {
        return $this->repository->getDepartments();
    }

    public function show(Ticket $ticket): Ticket
    {
        return $this->repository->show($ticket);
    }

}
