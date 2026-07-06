<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-Client (docs/30-data-model/00-data-model.md#ent-client).
     * Write-owner: Module 3 CRM (ownership matrix). Created in the foundation
     * phase as a read-only FK anchor for the client → brand → product
     * hierarchy that Campaign (and therefore Module 1's Mention /
     * MonitoredSubject) depends on. Module 1 never writes this table.
     */
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('country', 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
