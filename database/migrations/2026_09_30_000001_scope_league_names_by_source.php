<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Imported competitions may share a name with a local league; local uniqueness is enforced by validation.
    public function up(): void
    {
        Schema::table('leagues', function (Blueprint $t) {
            $t->dropUnique(['name']);
            $t->unique(['source', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('leagues', function (Blueprint $t) {
            $t->dropUnique(['source', 'name']);
            $t->unique(['name']);
        });
    }
};
