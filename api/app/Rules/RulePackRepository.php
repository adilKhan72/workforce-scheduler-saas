<?php

namespace App\Rules;

use InvalidArgumentException;

/**
 * Loads versioned, effective-dated rule packs from resources/rule-packs/{id}/{version}.json (ADR-0004).
 */
final class RulePackRepository
{
    /** @var array<string, list<RulePack>> */
    private array $cache = [];

    public function __construct(private readonly string $path) {}

    /** The version of $packId in force on a local date. */
    public function effectiveOn(string $packId, string $localDate): RulePack
    {
        $candidates = array_filter($this->versions($packId), fn (RulePack $p) => $p->effectiveFrom <= $localDate);

        if ($candidates === []) {
            throw new InvalidArgumentException("No version of rule pack [{$packId}] is effective on {$localDate}.");
        }

        usort($candidates, fn (RulePack $a, RulePack $b) => strcmp($b->effectiveFrom, $a->effectiveFrom));

        return $candidates[0];
    }

    /** @return list<RulePack> */
    public function versions(string $packId): array
    {
        if (! preg_match('/^[a-z0-9-]+$/', $packId)) {
            throw new InvalidArgumentException("Invalid rule pack id [{$packId}].");
        }

        return $this->cache[$packId] ??= array_map(
            fn (string $file) => $this->load($packId, $file),
            glob("{$this->path}/{$packId}/*.json") ?: throw new InvalidArgumentException("Unknown rule pack [{$packId}]."),
        );
    }

    private function load(string $packId, string $file): RulePack
    {
        $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        if (($data['id'] ?? null) !== $packId || basename($file, '.json') !== ($data['version'] ?? null)) {
            throw new InvalidArgumentException("Rule pack file {$file} does not match its id/version.");
        }

        return new RulePack(
            $data['id'],
            $data['version'],
            $data['effective_from'],
            $data['description'] ?? '',
            array_map(fn (array $r) => [
                'type' => $r['type'],
                'severity' => Severity::from($r['severity']),
                'params' => $r['params'] ?? [],
            ], $data['rules']),
        );
    }
}
