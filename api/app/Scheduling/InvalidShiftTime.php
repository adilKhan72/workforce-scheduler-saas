<?php

namespace App\Scheduling;

use InvalidArgumentException;

final class InvalidShiftTime extends InvalidArgumentException
{
    public static function nonexistent(string $date, string $time, string $timezone): self
    {
        return new self("{$date} {$time} does not exist in {$timezone} (clocks skip it for daylight saving time).");
    }

    public static function unknownZone(string $timezone): self
    {
        return new self("Unknown time zone [{$timezone}].");
    }

    public static function badFormat(string $value): self
    {
        return new self("Invalid date/time [{$value}]; expected Y-m-d and H:i.");
    }

    public static function zeroLength(): self
    {
        return new self('A shift cannot start and end at the same local time.');
    }
}
