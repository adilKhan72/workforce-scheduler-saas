<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swap_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shift_assignment_id');
            $table->uuid('from_staff_member_id');
            $table->uuid('to_staff_member_id');
            $table->string('reason')->nullable();
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'shift_assignment_id'])->references(['tenant_id', 'id'])->on('shift_assignments')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'from_staff_member_id'])->references(['tenant_id', 'id'])->on('staff_members')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'to_staff_member_id'])->references(['tenant_id', 'id'])->on('staff_members')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
        });

        Rls::enable('swap_requests');
    }

    public function down(): void
    {
        Schema::dropIfExists('swap_requests');
    }
};
