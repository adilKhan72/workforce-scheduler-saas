<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Turns a shift's wall-clock definition into UTC instants (ADR-0002).
 *
 * - end_local <= start_local means the shift ends on the next local day.
 * - A local time skipped by a DST transition is rejected (PHP would silently shift it an hour).
 * - A local time repeated by a DST transition resolves to its first occurrence, and is flagged.
 * - Durations come from instants, so a 22:00-06:00 night shift is 7 h or 9 h on transition nights.
 */
final readonly class ShiftTime
{
    private function __construct(
        public string $localDate,
        public string $startLocal,
        public string $endLocal,
        public string $timezone,
        public CarbonImmutable $startUtc,
        public CarbonImmutable $endUtc,
        public bool $ambiguous,
    ) {}

    public static function resolve(string $localDate, string $startLocal, string $endLocal, string $timezone): self
    {
        $zone = self::zone($timezone);
        $startLocal = self::normaliseTime($startLocal);
        $endLocal = self::normaliseTime($endLocal);
        self::assertDate($localDate);

        if ($startLocal === $endLocal) {
            throw InvalidShiftTime::zeroLength();
        }

        $endDate = $endLocal < $startLocal
            ? (new DateTimeImmutable($localDate))->modify('+1 day')->format('Y-m-d')
            : $localDate;

        [$start, $startAmbiguous] = self::instant($localDate, $startLocal, $zone);
        [$end, $endAmbiguous] = self::instant($endDate, $endLocal, $zone);

        return new self(
            $localDate, $startLocal, $endLocal, $timezone,
            CarbonImmutable::createFromTimestampUTC($start),
            CarbonImmutable::createFromTimestampUTC($end),
            $startAmbiguous || $endAmbiguous,
        );
    }

    public function minutes(): int
    {
        return intdiv($this->endUtc->getTimestamp() - $this->startUtc->getTimestamp(), 60);
    }

    public function hours(): float
    {
        return $this->minutes() / 60;
    }

    /** True when the UTC offset differs between start and end (the shift spans a DST change). */
    public function crossesDstTransition(): bool
    {
        $zone = new DateTimeZone($this->timezone);

        return $zone->getOffset($this->startUtc->toDateTimeImmutable())
            !== $zone->getOffset($this->endUtc->toDateTimeImmutable());
    }

    /**
     * Monday (local) of the week containing a local date. Weeks are local to the location.
     */
    public static function weekStart(string $localDate): string
    {
        self::assertDate($localDate);

        return (new DateTimeImmutable($localDate))->modify('monday this week')->format('Y-m-d');
    }

    /**
     * Find every UTC instant whose wall-clock time in $zone is "$date $time".
     *
     * @return array{0: int, 1: bool} [unix timestamp of the first occurrence, ambiguous?]
     */
    private static function instant(string $date, string $time, DateTimeZone $zone): array
    {
        // The wall-clock reading interpreted as if it were UTC.
        $wall = (new DateTimeImmutable("{$date} {$time}:00", new DateTimeZone('UTC')))->getTimestamp();

        $offsets = [];
        foreach ($zone->getTransitions($wall - 2 * 86400, $wall + 2 * 86400) ?: [] as $transition) {
            $offsets[$transition['offset']] = true;
        }

        $candidates = [];
        foreach (array_keys($offsets) as $offset) {
            $utc = $wall - $offset;
            if ($zone->getOffset(new DateTimeImmutable("@{$utc}")) === $offset) {
                $candidates[] = $utc;
            }
        }

        if ($candidates === []) {
            throw InvalidShiftTime::nonexistent($date, $time, $zone->getName());
        }

        sort($candidates);

        return [$candidates[0], count($candidates) > 1];
    }

    private static function zone(string $timezone): DateTimeZone
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw InvalidShiftTime::unknownZone($timezone);
        }

        return new DateTimeZone($timezone);
    }

    private static function normaliseTime(string $time): string
    {
        // Accept H:i and H:i:s (PostgreSQL returns time columns as HH:MM:SS).
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:00)?$/', $time)) {
            throw InvalidShiftTime::badFormat($time);
        }

        return substr($time, 0, 5);
    }

    private static function assertDate(string $date): void
    {
        try {
            $parsed = new DateTimeImmutable($date);
        } catch (Exception) {
            throw InvalidShiftTime::badFormat($date);
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $parsed->format('Y-m-d') !== $date) {
            throw InvalidShiftTime::badFormat($date);
        }
    }
}
