<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // The Proxmox VM name when it is not the hostname: the primary address of a
            // server named from its location's template (docs/adr/0031). Null: the hostname.
            $table->string('vm_name', 253)->nullable()->after('hostname');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('vm_name');
        });
    }
};
