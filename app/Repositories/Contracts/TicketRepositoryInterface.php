<?php

namespace App\Repositories\Contracts;

use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

interface TicketRepositoryInterface
{
    public function query(): Builder;

    public function create(array $data);

    public function getDepartments();

    public function list();

    public function show(Ticket $ticket);
}
