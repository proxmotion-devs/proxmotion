<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Servers can be created with custom resources (no package). The limits that
 * used to be read from the package are now stored on the server itself.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->foreignId('package_id')->nullable()->change();
            $table->unsignedInteger('port_speed_mbps')->nullable()->after('disk_mib');
            $table->unsignedBigInteger('traffic_bytes')->nullable()->after('port_speed_mbps');
        });

        // Existing servers keep the limits of their package.
        foreach (DB::table('packages')->get(['id', 'port_speed_mbps', 'traffic_bytes']) as $package) {
            DB::table('servers')->where('package_id', $package->id)->update([
                'port_speed_mbps' => $package->port_speed_mbps,
                'traffic_bytes' => $package->traffic_bytes,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['port_speed_mbps', 'traffic_bytes']);
        });
    }
};
