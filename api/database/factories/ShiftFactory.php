<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'local_date' => '2026-10-19',
            'start_local' => '09:00',
            'end_local' => '17:00',
            'job_role' => 'barista',
        ];
    }
}
