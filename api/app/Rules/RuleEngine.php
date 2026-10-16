<?php

namespace App\Rules;

use App\Rules\Types\MaxHoursPerDay;
use App\Rules\Types\MaxHoursPerWeek;
use App\Rules\Types\MinorWorkerRestrictions;
use App\Rules\Types\MinRestBetweenShifts;
use App\Rules\Types\NoOverlappingShifts;
use App\Rules\Types\RequiredSkill;
use App\Rules\Types\WithinAvailability;
use InvalidArgumentException;

/**
 * Rules are code, parameters are data (ADR-0004). Pure: callers load the snapshots.
 */
final class RuleEngine
{
    /** @var array<string, class-string<Rule>> */
    public const TYPES = [
        'no_overlapping_shifts' => NoOverlappingShifts::class,
        'required_skill' => RequiredSkill::class,
        'min_rest_between_shifts' => MinRestBetweenShifts::class,
        'max_hours_per_week' => MaxHoursPerWeek::class,
        'max_hours_per_day' => MaxHoursPerDay::class,
        'minor_worker_restrictions' => MinorWorkerRestrictions::class,
        'within_availability' => WithinAvailability::class,
    ];

    /** @return list<RuleViolation> */
    public function evaluate(RulePack $pack, Candidate $candidate): array
    {
        $violations = [];

        foreach ($pack->rules as $rule) {
            $class = self::TYPES[$rule['type']] ?? throw new InvalidArgumentException("Unknown rule type [{$rule['type']}] in {$pack->id} {$pack->version}.");

            foreach ((new $class)->check($candidate, $rule['params']) as $finding) {
                $violations[] = new RuleViolation(
                    $rule['type'], $rule['severity'], $pack->id, $pack->version,
                    "{$finding->message} ({$pack->id} {$pack->version})",
                    $candidate->staff->id, $finding->shiftIds,
                );
            }
        }

        return $violations;
    }
}
