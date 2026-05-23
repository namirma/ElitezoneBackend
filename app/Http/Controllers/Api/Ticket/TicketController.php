<?php

namespace App\Http\Controllers\Api\Ticket;

use App\DataTransferObjects\Ticket\TicketDTO;
use App\Http\Controllers\Controller;
use App\Http\Resources\Shared\CreatedResource;
use App\Http\Resources\Ticket\DepartmentResource;
use App\Http\Resources\Ticket\TicketResource;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function __construct(protected TicketService $service) {}

    public function index(): JsonResponse
    {
        return success(TicketResource::collection($this->service->list()));
    }

    public function store(Request $request): JsonResponse
    {
        $dto = TicketDTO::fromRequest($request);
        $ticket = $this->service->create($dto);

        return created(new CreatedResource($ticket), 'ticket.createdSuccessfully');
    }

    public function show(Ticket $ticket): JsonResponse
    {
        return success(new TicketResource($this->service->show($ticket)));
    }

    public function getDepartments(): JsonResponse
    {
        return success(DepartmentResource::collection($this->service->getDepartments()));
    }
}
