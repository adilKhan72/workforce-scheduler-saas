<?php

namespace Database\Factories;

use App\Models\StaffAvailability;
use App\Models\StaffMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffAvailability>
 */
class StaffAvailabilityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'staff_member_id' => StaffMember::factory(),
            'day_of_week' => 1,
            'start_local' => '08:00',
            'end_local' => '18:00',
        ];
    }
}
