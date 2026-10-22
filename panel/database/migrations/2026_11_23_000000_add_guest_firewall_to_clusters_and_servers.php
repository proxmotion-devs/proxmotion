<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clusters', function (Blueprint $table) {
            // The datacenter firewall options the sync last read (docs/adr/0033):
            // without them a VM's ipfilter and macfilter do nothing. Null: not read yet.
            $table->boolean('firewall_enabled')->nullable()->after('last_sync_error');
            $table->boolean('firewall_ebtables')->nullable()->after('firewall_enabled');
        });

        Schema::table('servers', function (Blueprint $table) {
            // When the sync last compared the VM's firewall with what the panel set (docs/adr/0033).
            $table->timestamp('firewall_checked_at')->nullable()->after('verification');
        });
    }

    public function down(): void
    {
        Schema::table('clusters', function (Blueprint $table) {
            $table->dropColumn(['firewall_enabled', 'firewall_ebtables']);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('firewall_checked_at');
        });
    }
};
