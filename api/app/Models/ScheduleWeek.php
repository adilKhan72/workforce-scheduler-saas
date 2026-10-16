<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One location's schedule for one local week (Monday to Monday).
 */
class ScheduleWeek extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    protected $fillable = ['location_id', 'week_start', 'status', 'published_version', 'published_at'];

    protected $attributes = ['status' => self::DRAFT, 'published_version' => 0];

    protected function casts(): array
    {
        return [
            'published_version' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<Shift, $this> */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }
}
