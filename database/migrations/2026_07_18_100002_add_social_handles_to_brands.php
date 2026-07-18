<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', fn (Blueprint $t) => $t->jsonb('social_handles')->nullable()->after('aliases'));
    }

    public function down(): void
    {
        Schema::table('brands', fn (Blueprint $t) => $t->dropColumn('social_handles'));
    }
};
