<?php

namespace App\Rules;

/**
 * An explainable result: which rule, which pack version, the numbers, and the shifts involved.
 */
final readonly class RuleViolation
{
    /** @param list<string> $shiftIds */
    public function __construct(
        public string $rule,
        public Severity $severity,
        public string $packId,
        public string $packVersion,
        public string $message,
        public string $staffMemberId,
        public array $shiftIds,
    ) {}

    public function isBlocking(): bool
    {
        return $this->severity === Severity::Block;
    }

    /** @return array<string, mixed> GraphQL-ready shape */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'severity' => $this->severity->value,
            'packId' => $this->packId,
            'packVersion' => $this->packVersion,
            'message' => $this->message,
            'staffMemberId' => $this->staffMemberId,
            'shiftIds' => $this->shiftIds,
        ];
    }

    /**
     * @param  list<self>  $violations
     * @return list<array<string, mixed>>
     */
    public static function toArrays(array $violations): array
    {
        return array_map(fn (self $v) => $v->toArray(), $violations);
    }

    /** Identity for de-duplication: the same rest violation is found from both shifts on publish. */
    public function key(): string
    {
        $ids = $this->shiftIds;
        sort($ids);

        return $this->rule.'|'.$this->staffMemberId.'|'.implode(',', $ids);
    }
}
