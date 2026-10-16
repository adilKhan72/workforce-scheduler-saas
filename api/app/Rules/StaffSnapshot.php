<?php

namespace App\Rules;

use App\Models\StaffMember;
use Carbon\CarbonImmutable;

final readonly class StaffSnapshot
{
    /**
     * @param  list<string>  $skills
     * @param  list<array{day: int, start: string, end: string}>  $availability  empty = no restriction recorded
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $skills,
        public CarbonImmutable $dateOfBirth,
        public float $contractedHoursPerWeek = 0,
        public array $availability = [],
    ) {}

    public static function fromModel(StaffMember $staff): self
    {
        return new self(
            $staff->id,
            $staff->name,
            array_values($staff->skills ?? []),
            $staff->date_of_birth,
            (float) $staff->contracted_hours_per_week,
            $staff->availabilities->map(fn ($a) => [
                'day' => (int) $a->day_of_week,
                'start' => substr((string) $a->start_local, 0, 5),
                'end' => substr((string) $a->end_local, 0, 5),
            ])->values()->all(),
        );
    }

    public function ageOn(string $localDate): int
    {
        return (int) $this->dateOfBirth->diffInYears(CarbonImmutable::parse($localDate), true);
    }
}
