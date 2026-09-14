<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use Hekmatinasser\Verta\Verta;
use Illuminate\Support\Carbon;

/**
 * Jalali date/time for display, in the users' zone.
 *
 * Storage and every comparison stay in UTC (`app.timezone`); only the string a person
 * reads moves to `app.display_timezone`. Without the shift Verta formats the UTC wall
 * clock, so every time shown in Iran was 3.5 hours early.
 *
 * Verta keeps the zone of a DateTime it is given, so the instant is converted first. A
 * string is parsed in `app.timezone`, i.e. read as the UTC value the database holds.
 * Pinned by `Localization/DisplayTimezoneTest`.
 */
final class JalaliDate
{
    public static function format(DateTimeInterface|string $at, string $format = 'Y/m/d H:i'): string
    {
        $instant = $at instanceof DateTimeInterface ? Carbon::instance($at) : Carbon::parse($at);

        return (new Verta($instant->setTimezone((string) config('app.display_timezone'))))->format($format);
    }
}
