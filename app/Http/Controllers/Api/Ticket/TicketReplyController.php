<?php

namespace App\Http\Controllers\Api\Ticket;

use App\DataTransferObjects\Ticket\TicketReplyDTO;
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
        // ⚠️ بدونِ try/catch، عمداً. نسخه‌ی قبل هر خطا را با
        // `error($e->getMessage(), [], $e->getCode())` برمی‌گرداند: `ValidationException`
        // (کدِ ۰) به وضعیتِ HTTPِ نامعتبرِ ۰ می‌رسید و خودش ۵۰۰ می‌شد — به‌جای ۴۲۲ با
        // خطای فیلد — و خطای دیتابیس متنِ SQL را نشان می‌داد. هندلرِ لاراول هر سه را
        // درست می‌سازد. (کامنت و نه docblock: Scramble docblockِ متد را خلاصه‌ی عمومیِ
        // اندپوینت در `api.json` می‌کند.)
        $dto = TicketReplyDTO::fromRequest($request);
        $ticketReply = $this->service->create($dto, $ticket->id);

        return created(new CreatedResource($ticketReply), 'ticket.replyCreatedSuccessfully');
    }
}
