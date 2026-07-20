<?php

namespace App\Jobs\Ticketing;

use App\Models\Ticket;
use App\Models\TicketNote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * اسکالیشن خودکار تیکت‌های عبورکرده از SLA (cron ساعتی).
 *
 * تیکت باز که از sla_due_at گذشته و هنوز اسکاله نشده:
 * priority → urgent + یادداشت داخلی + مهر escalated_at (دقیقاً یک‌بار).
 */
class EscalateOverdueTicketsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        if (! config('ticketing.escalation.enabled', true)) {
            return;
        }

        Ticket::query()
            ->whereIn(Ticket::STATUS, config('ticketing.open_statuses', []))
            ->whereNotNull(Ticket::SLA_DUE_AT)
            ->where(Ticket::SLA_DUE_AT, '<', now())
            ->whereNull('escalated_at')
            ->chunkById(100, function ($tickets) {
                foreach ($tickets as $ticket) {
                    try {
                        $ticket->forceFill([
                            Ticket::PRIORITY => Ticket::PRIORITY_URGENT,
                            'escalated_at' => now(),
                        ])->save();

                        TicketNote::create([
                            TicketNote::TICKET_ID => $ticket->id,
                            TicketNote::ADMIN_ID => null,
                            TicketNote::NOTE => __('ticket.escalated_note', [
                                'due' => (string) $ticket->{Ticket::SLA_DUE_AT},
                            ]),
                        ]);
                    } catch (\Exception $e) {
                        Log::error("Ticket escalation failed for #{$ticket->id}: ".$e->getMessage());
                    }
                }
            });
    }
}
