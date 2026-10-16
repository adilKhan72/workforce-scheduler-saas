<?php

namespace App\Rules;

final readonly class RulePack
{
    /** @param list<array{type: string, severity: Severity, params: array<string, mixed>}> $rules */
    public function __construct(
        public string $id,
        public string $version,
        public string $effectiveFrom,
        public string $description,
        public array $rules,
    ) {}
}
