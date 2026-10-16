<?php

namespace App\Rules;

final readonly class Finding
{
    /** @param list<string> $shiftIds */
    public function __construct(public string $message, public array $shiftIds) {}
}
