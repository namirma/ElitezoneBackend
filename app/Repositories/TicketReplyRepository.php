<?php

namespace App\Repositories;

use App\Contracts\TicketReplyRepositoryInterface;
use App\Models\TicketReply;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TicketReplyRepository implements TicketReplyRepositoryInterface
{

    public function query(): Builder
    {
        return TicketReply::query();
    }

    public function create(array $data): Model|Builder
    {
        return $this->query()->create($data);
    }
}
