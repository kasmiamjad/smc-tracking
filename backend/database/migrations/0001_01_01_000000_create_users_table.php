<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();
            $table->char('color_hex', 7);
            $table->string('icon_code', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('full_name', 150);
            $table->enum('role', ['ADMIN', 'DISPATCHER', 'TECHNICIAN'])->default('TECHNICIAN')->index();
            $table->enum('status', ['PENDING_APPROVAL', 'ACTIVE', 'REJECTED'])->default('PENDING_APPROVAL')->index();

            // Technicians: phone (E.164) + 4-digit PIN. Console users: email + password.
            $table->string('phone_number', 20)->nullable()->unique();
            $table->string('pin_hash')->nullable();
            $table->boolean('must_change_pin')->default(false);
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();

            $table->foreignUuid('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('avatar_path')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason', 500)->nullable();

            $table->timestamp('blocked_at')->nullable()->index();
            $table->foreignUuid('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('block_reason', 500)->nullable();

            // Consecutive failed logins; reaching the limit sets locked_at until an admin resets it.
            $table->unsignedTinyInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_at')->nullable();

            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            // Groups the access + refresh token issued by one login so they can be revoked together.
            $table->uuid('session_id')->nullable()->index();
            $table->string('device_name', 100)->nullable();
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('departments');
    }
};
