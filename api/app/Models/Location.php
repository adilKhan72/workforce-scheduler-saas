<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['name', 'timezone', 'rule_pack'];

    /** @return HasMany<Shift, $this> */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    /** @return HasMany<ScheduleWeek, $this> */
    public function scheduleWeeks(): HasMany
    {
        return $this->hasMany(ScheduleWeek::class);
    }
}
