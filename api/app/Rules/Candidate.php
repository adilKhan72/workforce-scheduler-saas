<?php

namespace App\Rules;

/**
 * "Would it be OK for $staff to work $shift, given the other shifts they already work?"
 */
final readonly class Candidate
{
    /** @param list<ShiftSnapshot> $otherShifts the staff member's other shifts around $shift */
    public function __construct(
        public StaffSnapshot $staff,
        public ShiftSnapshot $shift,
        public array $otherShifts,
    ) {}

    /** Hours on the same local date as the candidate shift, including it. */
    public function hoursOnDay(): float
    {
        return $this->sumWhere(fn (ShiftSnapshot $s) => $s->localDate === $this->shift->localDate);
    }

    /** Hours in the candidate's local week (Monday-Sunday), including it. Shifts count in the week they start. */
    public function hoursInWeek(): float
    {
        $week = $this->shift->weekStart();

        return $this->sumWhere(fn (ShiftSnapshot $s) => $s->weekStart() === $week);
    }

    /** @param callable(ShiftSnapshot): bool $filter */
    private function sumWhere(callable $filter): float
    {
        $total = $this->shift->hours();
        foreach ($this->otherShifts as $other) {
            if ($filter($other)) {
                $total += $other->hours();
            }
        }

        return $total;
    }
}
