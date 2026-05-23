<?php

namespace App\Http\Controllers\Api\Ticket;

use App\DataTransferObjects\TicketReplyDTO;
use App\Http\Controllers\Controller;
use App\Http\Resources\Shared\CreatedResource;
use App\Models\Ticket;
use App\Services\TicketReplyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketReplyController extends Controller
{
    public function __construct(protected TicketReplyService $service) {}

    public function store(Request $request, Ticket $ticket): JsonResponse
    {
        try {
            $dto = TicketReplyDTO::fromRequest($request);
            $ticketReply = $this->service->create($dto, $ticket->id);

            return created(new CreatedResource($ticketReply), 'ticket.replyCreatedSuccessfully');
        } catch (\Exception $e) {
            return error($e->getMessage(), [], $e->getCode());
        }
    }
}
