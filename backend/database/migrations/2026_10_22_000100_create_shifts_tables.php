<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MOD-07: shift templates. Times are wall-clock in the business timezone
        // (config smc.shifts.timezone); end <= start means the shift ends next day.
        Schema::create('shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();
            $table->time('start_time');
            $table->time('end_time');
            $table->json('days_of_week'); // ISO weekdays, 1 = Monday ... 7 = Sunday
            $table->json('geofence')->nullable(); // [{lat, lng}, ...] polygon, or null for open region
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // MOD-08/09: one technician on one shift on one date. Scheduled times are
        // snapshotted so editing a template never rewrites past timesheets.
        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('shift_id')->constrained()->restrictOnDelete();
            $table->date('shift_date');
            $table->timestamp('scheduled_start_at');
            $table->timestamp('scheduled_end_at');
            $table->enum('status', ['SCHEDULED', 'ACTIVE', 'COMPLETED', 'ABSENT'])->default('SCHEDULED');

            $table->timestamp('checked_in_at')->nullable();
            $table->decimal('check_in_lat', 10, 7)->nullable();
            $table->decimal('check_in_lng', 10, 7)->nullable();
            $table->boolean('check_in_outside_zone')->default(false);
            $table->timestamp('checked_out_at')->nullable();
            $table->decimal('check_out_lat', 10, 7)->nullable();
            $table->decimal('check_out_lng', 10, 7)->nullable();
            $table->boolean('auto_checked_out')->default(false);

            // Overtime = worked time beyond the scheduled shift length.
            $table->unsignedInteger('worked_minutes')->nullable();
            $table->unsignedInteger('regular_minutes')->nullable();
            $table->unsignedInteger('overtime_minutes')->nullable();

            $table->foreignUuid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'shift_id', 'shift_date']);
            $table->index(['user_id', 'status']);
            $table->index(['shift_date', 'status']);
            $table->index(['status', 'scheduled_end_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_assignments');
        Schema::dropIfExists('shifts');
    }
};
