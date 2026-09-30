<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Provider competition code (e.g. PL) so other feeds can find the league without relying on names.
    public function up(): void
    {
        Schema::table('leagues', fn (Blueprint $t) => $t->string('code', 10)->nullable()->after('external_id'));
    }

    public function down(): void
    {
        Schema::table('leagues', fn (Blueprint $t) => $t->dropColumn('code'));
    }
};
