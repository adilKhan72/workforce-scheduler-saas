<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An employer. Global table (not under RLS): it is read to authenticate, before a tenant is known.
 */
class Tenant extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'slug'];

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
