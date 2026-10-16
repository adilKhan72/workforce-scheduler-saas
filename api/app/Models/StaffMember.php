<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffMember extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['name', 'email', 'job_role', 'skills', 'date_of_birth', 'contracted_hours_per_week'];

    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'date_of_birth' => 'immutable_date',
            'contracted_hours_per_week' => 'float',
        ];
    }

    /** Age in whole years on a given local date (minor-worker rules depend on the shift date). */
    public function ageOn(string $localDate): int
    {
        return (int) $this->date_of_birth->diffInYears(CarbonImmutable::parse($localDate), true);
    }

    /** @return HasMany<StaffAvailability, $this> */
    public function availabilities(): HasMany
    {
        return $this->hasMany(StaffAvailability::class);
    }

    /** @return HasMany<ShiftAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }
}
