<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Timestamped bookmaker consensus snapshots; history is kept so forecasts compare with odds known before kickoff.
    public function up(): void
    {
        Schema::create('market_odds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fixture_id')->constrained()->cascadeOnDelete();
            $t->string('source');
            $t->string('external_id');
            $t->unsignedSmallInteger('bookmakers');
            $t->decimal('home_win', 8, 6);
            $t->decimal('draw', 8, 6);
            $t->decimal('away_win', 8, 6);
            $t->decimal('average_margin', 8, 6);
            $t->timestamp('observed_at');
            $t->timestamp('bookmaker_updated_at')->nullable();
            $t->timestamps();
            $t->index(['fixture_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_odds');
    }
};
