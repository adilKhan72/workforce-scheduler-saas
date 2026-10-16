<?php

namespace Database\Factories;

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\StaffMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShiftAssignment>
 */
class ShiftAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shift_id' => Shift::factory(),
            'staff_member_id' => StaffMember::factory(),
        ];
    }
}
