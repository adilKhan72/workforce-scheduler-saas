<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned scheduling tables (ADR-0001, ADR-0002).
 *
 * Conventions:
 * - tenant_id on every table, leading every index.
 * - UNIQUE (tenant_id, id) on parents, and composite foreign keys (tenant_id, parent_id) on
 *   children, so a row can never reference another tenant's row.
 * - Row-level security enabled and forced on every table.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'locations', 'staff_members', 'staff_availabilities', 'schedule_weeks', 'shifts', 'shift_assignments',
    ];

    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('timezone');            // IANA, e.g. America/New_York
            $table->string('rule_pack');           // e.g. us-ny (see rule-packs/)
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('staff_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('job_role');            // e.g. barista, supervisor
            $table->json('skills');                // e.g. ["barista", "food_safety"]
            $table->date('date_of_birth');         // minors get stricter rules
            $table->decimal('contracted_hours_per_week', 5, 2)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('staff_availabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('staff_member_id');
            $table->unsignedTinyInteger('day_of_week'); // ISO-8601: 1 = Monday .. 7 = Sunday
            $table->time('start_local');
            $table->time('end_local');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'staff_member_id'])->references(['tenant_id', 'id'])->on('staff_members')->cascadeOnDelete();
            $table->index(['tenant_id', 'staff_member_id', 'day_of_week']);
        });

        Schema::create('schedule_weeks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('location_id');
            $table->date('week_start');            // local Monday
            $table->string('status')->default('draft'); // draft | published
            $table->unsignedInteger('published_version')->default(0);
            $table->timestampTz('published_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'location_id'])->references(['tenant_id', 'id'])->on('locations')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'location_id', 'week_start']);
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('location_id');
            $table->uuid('schedule_week_id');
            // Source of truth (ADR-0002): wall-clock time + IANA zone.
            $table->date('local_date');
            $table->time('start_local');
            $table->time('end_local');             // <= start_local means next day
            $table->string('timezone');
            // Derived, for indexing and rule evaluation. Recomputed when tzdb_version is stale.
            $table->timestampTz('start_utc');
            $table->timestampTz('end_utc');
            $table->string('tzdb_version');
            $table->string('job_role');
            $table->string('required_skill')->nullable();
            $table->unsignedSmallInteger('headcount')->default(1);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'location_id'])->references(['tenant_id', 'id'])->on('locations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'schedule_week_id'])->references(['tenant_id', 'id'])->on('schedule_weeks')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'schedule_week_id']);
            $table->index(['tenant_id', 'location_id', 'start_utc']);
        });

        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shift_id');
            $table->uuid('staff_member_id');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'shift_id'])->references(['tenant_id', 'id'])->on('shifts')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'staff_member_id'])->references(['tenant_id', 'id'])->on('staff_members')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'shift_id', 'staff_member_id']);
            $table->index(['tenant_id', 'staff_member_id']);
        });

        foreach ($this->tables as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
