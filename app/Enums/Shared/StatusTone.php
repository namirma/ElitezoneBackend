<?php

namespace App\Enums\Shared;

/**
 * Canonical UI tone for every status value across all Resources.
 *
 * Every Resource that exposes a status_info object MUST source its tone from
 * here — never add ad-hoc colour/tone logic inside individual Resources.
 * To add a new status value, add a case to fromStatus() and nothing else.
 *
 * Rendered colours on the front-end:
 *   ongoing  → yellow  (in-progress / awaiting action)
 *   success  → green   (completed / approved / confirmed)
 *   failure  → red     (cancelled / rejected / failed / expired)
 *   neutral  → blue    (informational / new / unknown — safe default)
 *   closed   → grey    (archived / closed — terminal, no action needed)
 */
enum StatusTone: string
{
    case Ongoing = 'ongoing';
    case Success = 'success';
    case Failure = 'failure';
    case Neutral = 'neutral';
    case Closed  = 'closed';

    /**
     * Map any status key to its canonical tone.
     *
     * Unknown / future statuses fall through to Neutral so new statuses
     * never silently break the UI — they simply render blue until someone
     * adds an explicit case here.
     */
    public static function fromStatus(string $status): self
    {
        return match ($status) {

            // ── ongoing (yellow) — in-progress, awaiting action ───────────────
            'pending',
            'awaiting_payment',
            'current',
            'processing',
            'in_progress',
            'under_review',
            'preparing',
            'reserved',
            'reserved_extended',
            'pending_surplus_payment',
            'weighbridge_done',
            'waybill_issued',
            'shipped',
            'open',            // ticket: not yet answered
            'hold'             // wallet transaction: escrow hold
                => self::Ongoing,

            // ── success (green) — completed / approved / confirmed ─────────────
            'approved',
            'confirmed',
            'paid',
            'completed',
            'delivered',
            'answered',        // ticket: admin replied
            'verified',
            'active',
            'released'
                => self::Success,

            // ── failure (red) — cancelled / rejected / failed ─────────────────
            'rejected',
            'cancelled',
            'failed',
            'expired',
            'blocked'
                => self::Failure,

            // ── closed (grey) — terminal, no further action ───────────────────
            'closed',
            'archived'
                => self::Closed,

            // ── neutral (blue) — default for unknown / informational ──────────
            default => self::Neutral,
        };
    }
}
