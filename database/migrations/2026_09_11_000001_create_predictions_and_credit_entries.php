<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('predictions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('fixture_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('idempotency_key', 100);
            $t->string('status')->default('pending');
            $t->json('provider_snapshot');
            $t->json('fixture_snapshot');
            $t->json('result')->nullable();
            $t->string('error')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'idempotency_key']);
            $t->index(['company_id', 'fixture_id', 'status']);
            $t->index(['status', 'created_at']);
        });
        Schema::create('credit_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('prediction_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('idempotency_key', 150);
            $t->string('kind');
            $t->integer('amount');
            $t->unsignedInteger('balance_after');
            $t->timestamps();
            $t->unique(['company_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_entries');
        Schema::dropIfExists('predictions');
    }
};
