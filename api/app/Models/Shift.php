<?php

namespace App\Models;

use App\Scheduling\ShiftTime;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shift is defined in local wall-clock time plus an IANA zone; UTC instants are derived on save
 * (ADR-0002).
 *
 * @property string $local_date
 * @property string $start_local
 * @property string $end_local
 * @property string $timezone
 */
class Shift extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'location_id', 'local_date', 'start_local', 'end_local', 'job_role', 'required_skill', 'headcount',
    ];

    protected $attributes = ['headcount' => 1, 'version' => 1];

    protected function casts(): array
    {
        return [
            'start_utc' => 'immutable_datetime',
            'end_utc' => 'immutable_datetime',
            'headcount' => 'integer',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Shift $shift): void {
            $location = $shift->location()->firstOrFail();
            $shift->timezone = $location->timezone;

            $time = $shift->time();
            $shift->start_local = $time->startLocal;
            $shift->end_local = $time->endLocal;
            $shift->start_utc = $time->startUtc;
            $shift->end_utc = $time->endUtc;
            $shift->tzdb_version = timezone_version_get();

            $week = ScheduleWeek::firstOrCreate([
                'location_id' => $location->id,
                'week_start' => ShiftTime::weekStart($shift->local_date),
            ]);
            $shift->schedule_week_id = $week->id;

            if ($shift->exists && $shift->isDirty()) {
                $shift->version = $shift->getOriginal('version') + 1;
            }
        });
    }

    public function time(): ShiftTime
    {
        return ShiftTime::resolve($this->local_date, $this->start_local, $this->end_local, $this->timezone);
    }

    public function durationMinutes(): int
    {
        return $this->time()->minutes();
    }

    public function crossesDstTransition(): bool
    {
        return $this->time()->crossesDstTransition();
    }

    public function startLocalTime(): string
    {
        return substr((string) $this->start_local, 0, 5);
    }

    public function endLocalTime(): string
    {
        return substr((string) $this->end_local, 0, 5);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<ScheduleWeek, $this> */
    public function scheduleWeek(): BelongsTo
    {
        return $this->belongsTo(ScheduleWeek::class);
    }

    /** @return HasMany<ShiftAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }
}
