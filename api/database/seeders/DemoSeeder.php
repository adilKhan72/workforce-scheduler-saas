<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\StaffAvailability;
use App\Models\StaffMember;
use App\Models\Tenant;
use App\Models\User;
use App\Scheduling\ShiftTime;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Two fictional tenants so tenant isolation is visible in the demo:
 * - Northwind Coffee (New York, us-ny pack): manager@northwind.test / password
 * - Harbour Diner (London, gb pack): manager@harbour.test / password
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->tenant('Northwind Coffee', 'northwind', [
            ['Brooklyn Roastery', 'America/New_York', 'us-ny'],
            ['Midtown Kiosk', 'America/New_York', 'us-ny'],
        ]);
        $this->tenant('Harbour Diner', 'harbour', [
            ['Docklands', 'Europe/London', 'gb'],
        ]);
    }

    /** @param list<array{string, string, string}> $locations */
    private function tenant(string $name, string $slug, array $locations): void
    {
        $tenant = Tenant::create(['name' => $name, 'slug' => $slug]);
        User::create([
            'tenant_id' => $tenant->id, 'name' => "{$name} Manager", 'email' => "manager@{$slug}.test",
            'password' => 'password', 'role' => 'manager',
        ]);

        app(TenantContext::class)->run($tenant, function () use ($locations) {
            $staff = collect([
                ['Ava Martinez', 'supervisor', ['barista', 'supervisor', 'food_safety'], '1990-02-11', 40],
                ['Ben Okafor', 'barista', ['barista', 'food_safety'], '1998-07-30', 32],
                ['Chloe Nguyen', 'barista', ['barista'], '2001-12-03', 24],
                ['Dev Patel', 'cook', ['kitchen', 'food_safety'], '1994-04-18', 40],
                ['Ella Brooks', 'barista', ['barista'], '2010-06-15', 12], // a minor
            ])->map(fn (array $s) => StaffMember::create([
                'name' => $s[0], 'job_role' => $s[1], 'skills' => $s[2], 'date_of_birth' => $s[3],
                'contracted_hours_per_week' => $s[4],
            ]));

            foreach ($staff as $member) {
                foreach (range(1, 7) as $day) {
                    StaffAvailability::create([
                        'staff_member_id' => $member->id, 'day_of_week' => $day,
                        'start_local' => $member->ageOn(now()->toDateString()) < 18 ? '15:00' : '06:00',
                        'end_local' => $member->ageOn(now()->toDateString()) < 18 ? '21:00' : '23:00',
                    ]);
                }
            }

            $monday = CarbonImmutable::parse(ShiftTime::weekStart(now()->toDateString()));

            foreach ($locations as [$locationName, $zone, $pack]) {
                $location = Location::create(['name' => $locationName, 'timezone' => $zone, 'rule_pack' => $pack]);

                foreach (range(0, 6) as $offset) {
                    $date = $monday->addDays($offset)->toDateString();
                    $open = Shift::create([
                        'location_id' => $location->id, 'local_date' => $date, 'start_local' => '06:00',
                        'end_local' => '14:00', 'job_role' => 'barista', 'required_skill' => 'barista', 'headcount' => 2,
                    ]);
                    $close = Shift::create([
                        'location_id' => $location->id, 'local_date' => $date, 'start_local' => '14:00',
                        'end_local' => '22:00', 'job_role' => 'barista', 'required_skill' => 'barista', 'headcount' => 2,
                    ]);

                    // Partially staffed on purpose, so the dashboard has gaps to fill.
                    if ($offset < 5 && $locationName !== 'Midtown Kiosk') {
                        ShiftAssignment::create(['shift_id' => $open->id, 'staff_member_id' => $staff[$offset % 2 === 0 ? 0 : 1]->id]);
                        ShiftAssignment::create(['shift_id' => $close->id, 'staff_member_id' => $staff[2]->id]);
                    }
                }
            }
        });
    }
}
