<?php

namespace App\Enums\Survey;

/**
 * Survey states for an Order (Order::SURVEY_STATUS column).
 *
 * Mirrors Order::SURVEY_PENDING / SURVEY_REQUESTED / SURVEY_COMPLETED.
 */
enum SurveyStatus: string
{
    case Pending = 'pending';
    case Requested = 'requested';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'در انتظار',
            self::Requested => 'درخواست شده',
            self::Completed => 'تکمیل شده',
        };
    }
}
