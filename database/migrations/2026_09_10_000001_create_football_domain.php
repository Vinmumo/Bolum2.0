<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_admin')->default(false);
        });
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedInteger('credits')->default(10);
            $t->timestamps();
        });
        Schema::create('company_user', function (Blueprint $t) {
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('role')->default('member');
            $t->primary(['company_id', 'user_id']);
            $t->timestamps();
        });
        Schema::create('leagues', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('country');
            $t->timestamps();
        });
        Schema::create('teams', function (Blueprint $t) {
            $t->id();
            $t->foreignId('league_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->unique(['league_id', 'name']);
            $t->timestamps();
        });
        Schema::create('fixtures', function (Blueprint $t) {
            $t->id();
            $t->foreignId('league_id')->constrained()->restrictOnDelete();
            $t->foreignId('home_team_id')->constrained('teams')->restrictOnDelete();
            $t->foreignId('away_team_id')->constrained('teams')->restrictOnDelete();
            $t->timestamp('kickoff_at')->index();
            $t->boolean('is_finished')->default(false);
            $t->index(['league_id', 'kickoff_at']);
            $t->timestamps();
        });
        Schema::create('providers', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('driver');
            $t->decimal('weight', 8, 3)->default(1);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['providers', 'fixtures', 'teams', 'leagues', 'company_user', 'companies'] as $table) {
            Schema::dropIfExists($table);
        } Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_admin'));
    }
};
