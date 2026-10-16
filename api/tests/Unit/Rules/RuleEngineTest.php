<?php

namespace Tests\Unit\Rules;

use App\Rules\Candidate;
use App\Rules\RuleEngine;
use App\Rules\RulePack;
use App\Rules\RulePackRepository;
use App\Rules\Severity;
use App\Rules\ShiftSnapshot;
use App\Rules\StaffSnapshot;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RuleEngineTest extends TestCase
{
    private RulePackRepository $packs;

    protected function setUp(): void
    {
        $this->packs = new RulePackRepository(__DIR__.'/../../../resources/rule-packs');
    }

    public function test_the_version_in_force_on_the_shift_date_is_used(): void
    {
        $this->assertSame('2025.1', $this->packs->effectiveOn('us-ny', '2025-06-01')->version);
        $this->assertSame('2026.1', $this->packs->effectiveOn('us-ny', '2026-01-01')->version);
        $this->assertSame('2026.1', $this->packs->effectiveOn('us-ny', '2026-10-19')->version);
    }

    public function test_no_version_before_the_first_effective_date(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->packs->effectiveOn('us-ny', '2024-12-31');
    }

    public function test_unknown_or_malicious_pack_ids_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->packs->versions('../../etc');
    }

    public function test_every_shipped_pack_only_uses_known_rule_types(): void
    {
        $dirs = glob(__DIR__.'/../../../resources/rule-packs/*', GLOB_ONLYDIR);
        $this->assertNotEmpty($dirs);

        foreach ($dirs as $dir) {
            foreach ($this->packs->versions(basename($dir)) as $pack) {
                foreach ($pack->rules as $rule) {
                    $this->assertArrayHasKey($rule['type'], RuleEngine::TYPES, "{$pack->id} {$pack->version}");
                }
            }
        }
    }

    public function test_violations_name_the_rule_pack_version_and_severity(): void
    {
        $staff = new StaffSnapshot('st', 'Sam', ['barista'], CarbonImmutable::parse('1990-01-01'));
        $prev = ShiftSnapshot::local('a', '2026-10-19', '14:00', '23:00');
        $next = ShiftSnapshot::local('b', '2026-10-20', '06:00', '14:00'); // 7 h rest

        $violations = (new RuleEngine)->evaluate($this->packs->effectiveOn('us-ny', '2026-10-20'), new Candidate($staff, $next, [$prev]));

        $this->assertCount(1, $violations);
        $this->assertSame('min_rest_between_shifts', $violations[0]->rule);
        $this->assertSame(Severity::Warn, $violations[0]->severity);
        $this->assertSame('Rest between this shift and 2026-10-19 14:00-23:00 is 7.0 h; at least 11 h required. (us-ny 2026.1)', $violations[0]->message);

        // The same situation is blocking under the GB pack.
        $gb = (new RuleEngine)->evaluate($this->packs->effectiveOn('gb', '2026-10-20'), new Candidate($staff, $next, [$prev]));
        $this->assertTrue($gb[0]->isBlocking());
    }

    public function test_an_unknown_rule_type_in_a_pack_fails_loudly(): void
    {
        $pack = new RulePack('x', '1', '2026-01-01', '', [['type' => 'nope', 'severity' => Severity::Warn, 'params' => []]]);
        $staff = new StaffSnapshot('st', 'Sam', [], CarbonImmutable::parse('1990-01-01'));

        $this->expectException(InvalidArgumentException::class);

        (new RuleEngine)->evaluate($pack, new Candidate($staff, ShiftSnapshot::local('a', '2026-10-19', '09:00', '17:00'), []));
    }
}
