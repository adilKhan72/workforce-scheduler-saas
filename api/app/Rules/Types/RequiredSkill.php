<?php

namespace App\Rules\Types;

use App\Rules\Candidate;
use App\Rules\Finding;
use App\Rules\Rule;

final class RequiredSkill implements Rule
{
    public function check(Candidate $candidate, array $params): array
    {
        $skill = $candidate->shift->requiredSkill;

        if ($skill === null || in_array($skill, $candidate->staff->skills, true)) {
            return [];
        }

        return [new Finding("{$candidate->staff->name} does not have the required skill \"{$skill}\".", [$candidate->shift->id])];
    }
}
