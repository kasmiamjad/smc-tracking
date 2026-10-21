<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['full_name', 'role', 'status', 'phone_number', 'pin_hash', 'must_change_pin', 'email', 'password', 'department_id'])]
#[Hidden(['pin_hash', 'password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $attributes = ['must_change_pin' => false, 'failed_login_attempts' => 0];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'pin_hash' => 'hashed',
            'password' => 'hashed',
            'must_change_pin' => 'boolean',
            'approved_at' => 'datetime',
            'blocked_at' => 'datetime',
            'locked_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(UserDocument::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /** May this user hold a session right now? */
    public function canAuthenticate(): bool
    {
        return $this->status === UserStatus::Active && ! $this->isBlocked() && ! $this->isLocked();
    }

    public function scopeTechnicians(Builder $query): void
    {
        $query->where('role', UserRole::Technician);
    }
}
