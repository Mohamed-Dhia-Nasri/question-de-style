<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage D schema foundation: a lightweight campaign brief — `objective`
 * (free text) and `markets` (jsonb list of market codes) — both nullable,
 * no FK, additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->text('objective')->nullable();
            $table->jsonb('markets')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', fn (Blueprint $t) => $t->dropColumn(['objective', 'markets']));
    }
};
