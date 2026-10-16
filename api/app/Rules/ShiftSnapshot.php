<?php

namespace App\Rules;

use App\Models\Shift;
use App\Scheduling\ShiftTime;
use Carbon\CarbonImmutable;

/**
 * Immutable input to the rule engine: no database access past this point.
 */
final readonly class ShiftSnapshot
{
    public function __construct(
        public string $id,
        public string $localDate,
        public string $startLocal,
        public string $endLocal,
        public string $timezone,
        public CarbonImmutable $startUtc,
        public CarbonImmutable $endUtc,
        public ?string $requiredSkill = null,
    ) {}

    public static function fromModel(Shift $shift): self
    {
        $time = $shift->time();

        return new self(
            $shift->id, $time->localDate, $time->startLocal, $time->endLocal, $time->timezone,
            $time->startUtc, $time->endUtc, $shift->required_skill,
        );
    }

    /** Test/factory helper: build from wall-clock values. */
    public static function local(string $id, string $date, string $start, string $end, string $timezone = 'America/New_York', ?string $requiredSkill = null): self
    {
        $t = ShiftTime::resolve($date, $start, $end, $timezone);

        return new self($id, $t->localDate, $t->startLocal, $t->endLocal, $timezone, $t->startUtc, $t->endUtc, $requiredSkill);
    }

    public function hours(): float
    {
        return ($this->endUtc->getTimestamp() - $this->startUtc->getTimestamp()) / 3600;
    }

    public function overlaps(self $other): bool
    {
        return $this->startUtc < $other->endUtc && $other->startUtc < $this->endUtc;
    }

    /** Local Monday of this shift's week (weeks are local to the shift's location). */
    public function weekStart(): string
    {
        return ShiftTime::weekStart($this->localDate);
    }

    /** ISO day of week (1 = Monday) of the local date. */
    public function dayOfWeek(): int
    {
        return (int) CarbonImmutable::parse($this->localDate)->isoWeekday();
    }

    public function crossesMidnight(): bool
    {
        return $this->endLocal <= $this->startLocal;
    }
}
