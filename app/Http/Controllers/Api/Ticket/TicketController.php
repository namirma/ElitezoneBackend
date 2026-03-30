<?php

namespace App\Http\Controllers\Api\Ticket;

use App\DataTransferObjects\TicketDTO;
use App\Http\Controllers\Controller;
use App\Http\Resources\CreatedResource;
use App\Http\Resources\DepartmentResource;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function __construct(protected TicketService $service)
    {
    }

    public function index(): JsonResponse
    {
        return success(TicketResource::collection($this->service->list()));
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $dto = TicketDTO::fromRequest($request);
            $ticket = $this->service->create($dto);

            return created(new CreatedResource($ticket), 'ticket.createdSuccessfully');
        } catch (\Exception $e) {
            return error($e->getMessage(), [], $e->getCode());
        }
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
