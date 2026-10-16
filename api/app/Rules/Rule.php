<?php

namespace App\Rules;

/**
 * A rule type: pure, deterministic, no I/O. Parameters come from the rule pack (ADR-0004).
 */
interface Rule
{
    /**
     * @param  array<string, mixed>  $params
     * @return list<Finding>
     */
    public function check(Candidate $candidate, array $params): array;
}
