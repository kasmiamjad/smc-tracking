<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 6.3: raw pings are kept 30 days; each finished shift's route is kept
     * permanently as a simplified path (at most a few hundred points) with its
     * stops and figures, so route history still works after the raw data is gone.
     */
    public function up(): void
    {
        Schema::create('route_summaries', function (Blueprint $table) {
            $table->foreignUuid('shift_assignment_id')->primary()->constrained()->cascadeOnDelete();
            $table->uuid('user_id')->index();
            $table->json('path');   // [[lat, lng, unixMs], ...] simplified, cleaned
            $table->json('stops');  // [{lat, lng, from, to, durationSeconds}, ...]
            $table->json('stats');  // RouteCleaner stats over the full raw data
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_summaries');
    }
};
