<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'color_hex', 'icon_code'])]
class Department extends Model
{
    use HasFactory, HasUuids;

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
