<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nodes removed from their Proxmox cluster can be removed from the panel
 * (docs/adr/0057). They are soft-deleted, because terminated servers and
 * past moves keep pointing at them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
