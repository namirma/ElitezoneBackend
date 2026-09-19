<?php

namespace Tests\Feature\Ticketing;

use App\Support\BusinessHours;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تقویم ساعتِ کاری باید محلی فکر کند، حتی وقتی ورودی UTC است.
 *
 * `app.timezone` روی UTC است و `TicketService` با `now()` صدا می‌زند، ولی
 * «۹ تا ۱۷» و «جمعه تعطیل» مفاهیمِ تهران‌اند. پیش از اصلاح، مقایسه روی ساعتِ
 * UTC انجام می‌شد و کلِ تقویم ۳٫۵ ساعت جابه‌جا بود.
 */
class BusinessHoursTimezoneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ticketing.business_hours.enabled' => true,
            'ticketing.business_hours.start_hour' => 9,
            'ticketing.business_hours.end_hour' => 17,
            'ticketing.business_hours.timezone' => 'Asia/Tehran',
            'ticketing.business_hours.weekend_days' => [Carbon::FRIDAY],
        ]);
    }

    #[Test]
    public function a_ticket_opened_after_hours_gets_a_deadline_inside_the_next_working_day(): void
    {
        // ۲۰:۰۰ تهران = ۱۶:۳۰ UTC. پیش از اصلاح، ۱۶ < ۱۷ بود پس «داخل ساعت کاری»
        // شمرده می‌شد و مهلت نیمه‌شب می‌افتاد.
        $start = Carbon::parse('2026-08-02 20:00:00', 'Asia/Tehran')->setTimezone('UTC');

        $due = BusinessHours::addWorkingHours($start, 4);
        $dueTehran = $due->copy()->setTimezone('Asia/Tehran');

        $this->assertGreaterThanOrEqual(9, $dueTehran->hour, 'مهلت باید داخل ساعت کاری بیفتد.');
        $this->assertLessThanOrEqual(17, $dueTehran->hour);
        $this->assertGreaterThan($start, $due);
    }

    #[Test]
    public function a_ticket_opened_late_morning_is_not_treated_as_before_opening(): void
    {
        // ۱۱:۰۰ تهران = ۰۷:۳۰ UTC — پیش از اصلاح «قبل از ۹» دیده می‌شد و ساعتِ
        // شروعِ SLA به جلو پرت می‌شد.
        $start = Carbon::parse('2026-08-02 11:00:00', 'Asia/Tehran')->setTimezone('UTC');

        $due = BusinessHours::addWorkingHours($start, 2);
        $dueTehran = $due->copy()->setTimezone('Asia/Tehran');

        // ۱۱ + ۲ ساعتِ کاری = ۱۳ همان روز.
        $this->assertSame(13, $dueTehran->hour);
        $this->assertSame('2026-08-02', $dueTehran->toDateString());
    }

    #[Test]
    public function friday_is_still_skipped_when_reasoning_in_local_time(): void
    {
        // پنجشنبه ۱۶:۰۰ تهران، ۴ ساعت کاری → باید از جمعه رد شود.
        $thursday = Carbon::parse('2026-08-06 16:00:00', 'Asia/Tehran');
        $this->assertSame(Carbon::THURSDAY, $thursday->dayOfWeek, 'فرضِ فیکسچر: پنجشنبه');

        $due = BusinessHours::addWorkingHours($thursday->copy()->setTimezone('UTC'), 4);
        $dueTehran = $due->copy()->setTimezone('Asia/Tehran');

        $this->assertNotSame(Carbon::FRIDAY, $dueTehran->dayOfWeek, 'مهلت نباید روی جمعه بیفتد.');
    }

    #[Test]
    public function the_returned_instant_keeps_the_callers_timezone(): void
    {
        // خروجی مستقیم در `sla_due_at` ذخیره می‌شود و بقیه‌ی سیستم UTC می‌نویسد.
        $start = Carbon::parse('2026-08-02 10:00:00', 'UTC');

        $due = BusinessHours::addWorkingHours($start, 1);

        $this->assertSame('UTC', $due->getTimezone()->getName());
    }

    #[Test]
    public function a_disabled_calendar_still_falls_back_to_wall_clock(): void
    {
        config(['ticketing.business_hours.enabled' => false]);

        $start = Carbon::parse('2026-08-02 20:00:00', 'UTC');
        $due = BusinessHours::addWorkingHours($start, 3);

        $this->assertEqualsWithDelta(3 * 60, $start->diffInMinutes($due), 0.001);
    }
}
