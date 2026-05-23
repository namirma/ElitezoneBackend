<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Ticket\TicketResource;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTicketController extends Controller
{
    // public function index(Request $request): JsonResponse
    // {
    //     $tickets = Ticket::query()
    //         ->with('user:id,name,mobile')
    //         ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
    //         ->when($request->filled('priority'), fn($q) => $q->where('priority', $request->priority))
    //         ->latest()
    //         ->paginate(20);

    //     return success(TicketResource::collection($tickets));
    // }

    // public function show(Ticket $ticket): JsonResponse
    // {
    //     $ticket->load(['messages.user', 'attachments']);
    //     return success(new TicketResource($ticket));
    // }

    // public function reply(Request $request, Ticket $ticket): JsonResponse
    // {
    //     $request->validate([
    //         'message' => 'required|string',
    //         'attachment' => 'nullable|file|max:5120' // 5MB Max
    //     ]);

    //     $message = $ticket->messages()->create([
    //         'user_id' => auth()->id(),
    //         'message' => $request->input('message'),
    //         'is_admin_reply' => true,
    //     ]);

    //     // هندل کردن آپلود فایل (اینجا ساده شده)
    //     if ($request->hasFile('attachment')) {
    //          $message->addMediaFromRequest('attachment')->toMediaCollection('ticket_attachments');
    //     }

    //     $ticket->update(['status' => 'answered']);

    //     return success(null, __('admin.tickets.reply_sent'));
    // }

    // public function close(Ticket $ticket): JsonResponse
    // {
    //     $ticket->update(['status' => 'closed']);
    //     return success(null, __('admin.tickets.closed'));
    // }
}
