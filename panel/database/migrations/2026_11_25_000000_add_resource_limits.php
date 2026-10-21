<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['packages', 'servers'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                // Limits a server gets in Proxmox (docs/adr/0034); null: unlimited.
                $table->decimal('cpu_limit', 5, 2)->nullable()->after('port_speed_mbps');
                $table->unsignedInteger('cpu_units')->nullable()->after('cpu_limit');
                $table->unsignedInteger('disk_mb_s')->nullable()->after('cpu_units');
                $table->unsignedInteger('disk_iops')->nullable()->after('disk_mb_s');
            });
        }
    }

    public function down(): void
    {
        foreach (['packages', 'servers'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['cpu_limit', 'cpu_units', 'disk_mb_s', 'disk_iops']);
            });
        }
    }
};
