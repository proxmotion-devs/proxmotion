<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How often billing systems may reinstall a package's servers within a
     * rolling window of days, and a server's own limit and count reset
     * (docs/adr/0045). An empty limit is unlimited.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedSmallInteger('reinstall_limit')->nullable()->after('disk_iops');
            $table->unsignedSmallInteger('reinstall_period_days')->nullable()->after('reinstall_limit');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->boolean('reinstall_limit_custom')->default(false)->after('usage_exempt');
            $table->unsignedSmallInteger('reinstall_limit')->nullable()->after('reinstall_limit_custom');
            $table->unsignedSmallInteger('reinstall_period_days')->nullable()->after('reinstall_limit');
            $table->timestamp('reinstalls_reset_at')->nullable()->after('reinstall_period_days');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['reinstall_limit_custom', 'reinstall_limit', 'reinstall_period_days', 'reinstalls_reset_at']);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['reinstall_limit', 'reinstall_period_days']);
        });
    }
};
