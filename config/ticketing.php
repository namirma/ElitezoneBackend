<?php

/**
 * Ticketing / support-desk configuration.
 *
 * Mirrors the design spec ("پروسه خرید" → بخش پشتیبانی): SLA, priorities and the
 * notification policy live here so they are never hardcoded in controllers/services.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | SLA first-response targets (hours), keyed by priority
    |--------------------------------------------------------------------------
    | Used by TicketService to stamp `sla_due_at` when a ticket is created.
    | NOTE: this is a simple wall-clock window. A working-hours calendar
    | (ساعت کاری در تیکتینگ) is a later refinement — see report.
    */
    'sla_hours' => [
        'urgent' => (int) env('TICKET_SLA_URGENT_HOURS', 2),
        'high' => (int) env('TICKET_SLA_HIGH_HOURS', 8),
        'normal' => (int) env('TICKET_SLA_NORMAL_HOURS', 24),
        'low' => (int) env('TICKET_SLA_LOW_HOURS', 72),
    ],

    /*
    |--------------------------------------------------------------------------
    | Statuses that count a ticket as "open" (not yet resolved/closed)
    |--------------------------------------------------------------------------
    | Used by KPI/aggregation queries.
    */
    'open_statuses' => ['open', 'in_progress', 'waiting_customer', 'waiting_supplier', 'answered', 'pending'],

    /*
    |--------------------------------------------------------------------------
    | Statuses that send an SMS to the ticket owner on transition
    |--------------------------------------------------------------------------
    | Spec: «اعلانها فقط برای تغییر وضعیت های مهم». Keep this list small.
    */
    'sms_on_status' => ['answered', 'resolved', 'closed'],
];
