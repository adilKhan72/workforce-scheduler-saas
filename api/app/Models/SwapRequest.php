<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SwapRequest extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = ['shift_assignment_id', 'from_staff_member_id', 'to_staff_member_id', 'reason'];

    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ShiftAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ShiftAssignment::class, 'shift_assignment_id');
    }

    /** @return BelongsTo<StaffMember, $this> */
    public function fromStaffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'from_staff_member_id');
    }

    /** @return BelongsTo<StaffMember, $this> */
    public function toStaffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'to_staff_member_id');
    }
}
