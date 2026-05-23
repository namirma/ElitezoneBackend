<?php

namespace App\Services;

use App\DataTransferObjects\Ticket\TicketDTO;
use App\Models\Ticket;
use App\Repositories\Contracts\TicketRepositoryInterface;
use Illuminate\Support\Facades\Auth;

class TicketService
{
    public function __construct(protected TicketRepositoryInterface $repository) {}

    public function list()
    {
        return $this->repository->list();
    }

    public function create(TicketDTO $dto)
    {
        // Inject authenticated user_id before persisting
        $data = $dto->toArray();
        $data['user_id'] = Auth::id();

        return $this->repository->create($data);
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
