<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MOD-11: raw pings, append-only. No foreign keys: this is the hot write
        // path (one row per technician every ~4s) and rows are purged after 30 days.
        Schema::create('location_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->uuid('shift_assignment_id');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->float('accuracy')->nullable();
            $table->float('speed')->nullable();
            $table->float('heading')->nullable();
            $table->unsignedTinyInteger('battery_level')->nullable();
            $table->boolean('is_charging')->nullable();
            $table->timestamp('recorded_at', 3);
            $table->timestamp('received_at')->useCurrent();

            $table->index(['user_id', 'recorded_at']);
            $table->index(['shift_assignment_id', 'recorded_at']);
            $table->index('recorded_at');
        });

        // Latest known position per technician, for the live map. Upserted on every batch.
        Schema::create('technician_positions', function (Blueprint $table) {
            $table->uuid('user_id')->primary();
            $table->uuid('shift_assignment_id');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->float('accuracy')->nullable();
            $table->float('speed')->nullable();
            $table->float('heading')->nullable();
            $table->unsignedTinyInteger('battery_level')->nullable();
            $table->boolean('is_charging')->nullable();
            $table->timestamp('recorded_at', 3);
            $table->timestamp('updated_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_positions');
        Schema::dropIfExists('location_logs');
    }
};
