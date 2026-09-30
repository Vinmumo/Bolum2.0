<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Gameweek filters and track records group by round; telemetry pruning filters on age alone.
    public function up(): void
    {
        Schema::table('fixtures', fn (Blueprint $t) => $t->index(['league_id', 'season', 'matchday']));
        Schema::table('provider_calls', fn (Blueprint $t) => $t->index('created_at'));
    }

    public function down(): void
    {
        Schema::table('provider_calls', fn (Blueprint $t) => $t->dropIndex(['created_at']));
        Schema::table('fixtures', fn (Blueprint $t) => $t->dropIndex(['league_id', 'season', 'matchday']));
    }
};
