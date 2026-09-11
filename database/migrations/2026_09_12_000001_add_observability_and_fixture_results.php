<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['leagues', 'teams', 'fixtures'] as $name) {
            Schema::table($name, function (Blueprint $t) {
                $t->string('source')->nullable();
                $t->string('external_id')->nullable();
                $t->unique(['source', 'external_id']);
            });
        }
        Schema::table('fixtures', function (Blueprint $t) {
            $t->string('season')->nullable();
            $t->unsignedSmallInteger('matchday')->nullable();
            $t->string('status')->default('scheduled')->index();
            $t->unsignedSmallInteger('home_goals')->nullable();
            $t->unsignedSmallInteger('away_goals')->nullable();
            $t->timestamp('result_recorded_at')->nullable();
        });
        Schema::table('predictions', function (Blueprint $t) {
            $t->timestamp('completed_at')->nullable()->index();
        });
        Schema::create('provider_calls', function (Blueprint $t) {
            $t->id();
            $t->string('source');
            $t->string('operation');
            $t->string('status');
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->unsignedInteger('duration_ms');
            $t->boolean('cache_hit')->default(false);
            $t->unsignedTinyInteger('attempt')->default(1);
            $t->timestamps();
            $t->index(['source', 'created_at']);
        });
        Schema::create('fixture_syncs', function (Blueprint $t) {
            $t->id();
            $t->string('status')->default('pending');
            $t->unsignedInteger('imported')->default(0);
            $t->string('error')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixture_syncs');
        Schema::dropIfExists('provider_calls');
        Schema::table('predictions', function (Blueprint $t) {
            $t->dropIndex(['completed_at']);
            $t->dropColumn('completed_at');
        });
        Schema::table('fixtures', function (Blueprint $t) {
            $t->dropIndex(['status']);
            $t->dropColumn(['season', 'matchday', 'status', 'home_goals', 'away_goals', 'result_recorded_at']);
        });
        foreach (['leagues', 'teams', 'fixtures'] as $name) {
            Schema::table($name, function (Blueprint $t) {
                $t->dropUnique(['source', 'external_id']);
                $t->dropColumn(['source', 'external_id']);
            });
        }
    }
};
