<?php

use Illuminate\Support\Carbon;

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

    /*
    |--------------------------------------------------------------------------
    | Business-hours calendar for SLA (ساعت کاری)
    |--------------------------------------------------------------------------
    | When enabled, SLA hours are consumed only inside working hours
    | (App\Support\BusinessHours) — a ticket opened Thursday evening gets its
    | deadline on Saturday. Disabled → plain wall-clock (previous behaviour).
    | weekend_days uses Carbon dayOfWeek numbers (Friday = 5).
    */
    'business_hours' => [
        'enabled' => (bool) env('TICKET_BUSINESS_HOURS_ENABLED', true),
        'start_hour' => (int) env('TICKET_BUSINESS_START_HOUR', 9),
        'end_hour' => (int) env('TICKET_BUSINESS_END_HOUR', 17),
        'weekend_days' => [Carbon::FRIDAY],

        // ساعتِ ۹ تا ۱۷ و «جمعه تعطیل» مفاهیمِ **محلی**اند، ولی `app.timezone`
        // روی UTC است و `TicketService` با `now()` صدا می‌زند. بدون این تبدیل،
        // تقویم ساعتِ UTC را با ساعتِ کاریِ ایران مقایسه می‌کرد و ۳٫۵ ساعت
        // جابه‌جا می‌شد — تیکتِ ساعت ۲۰ تهران «داخل ساعت کاری» شمرده می‌شد.
        'timezone' => env('TICKET_BUSINESS_TIMEZONE', 'Asia/Tehran'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Escalation automation
    |--------------------------------------------------------------------------
    | EscalateOverdueTicketsJob (hourly cron): open tickets past sla_due_at and
    | not yet escalated get priority=urgent + an internal TicketNote, and
    | escalated_at is stamped so the escalation fires exactly once per ticket.
    */
    'escalation' => [
        'enabled' => (bool) env('TICKET_ESCALATION_ENABLED', true),
    ],
];
