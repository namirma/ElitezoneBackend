<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;

interface TicketReplyRepositoryInterface
{
    public function query(): Builder;

    public function create(array $data);
}
