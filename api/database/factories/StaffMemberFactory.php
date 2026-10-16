<?php

namespace Database\Factories;

use App\Models\StaffMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffMember>
 */
class StaffMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'job_role' => 'barista',
            'skills' => ['barista'],
            'date_of_birth' => '1995-05-20',
            'contracted_hours_per_week' => 32,
        ];
    }

    public function minor(string $dateOfBirth = '2010-09-01'): static
    {
        return $this->state(['date_of_birth' => $dateOfBirth]);
    }
}
