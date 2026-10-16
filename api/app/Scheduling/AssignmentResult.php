<?php

namespace App\Scheduling;

use App\Models\ShiftAssignment;
use App\Rules\RuleViolation;

final readonly class AssignmentResult
{
    /** @param list<RuleViolation> $violations */
    public function __construct(public ?ShiftAssignment $assignment, public array $violations) {}

    public function blocked(): bool
    {
        return $this->assignment === null;
    }
}
