<?php

namespace App\Repositories;

use App\Models\Department;
use App\Models\Ticket;
use App\Repositories\Contracts\TicketRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class TicketRepository implements TicketRepositoryInterface
{
    public function query(): Builder
    {
        return Ticket::query();
    }

    public function create(array $data): Model|Builder
    {
        return $this->query()->create($data);
    }

    public function getDepartments()
    {
        return Department::IsAvailableForTicket()->get();
    }

    public function list(): Collection
    {
        return $this->query()->where('user_id', Auth::id())->latest()->get();
    }

    public function show(Ticket $ticket): Ticket
    {
        return $ticket->load(['user', 'replies.user']);
    }
}
