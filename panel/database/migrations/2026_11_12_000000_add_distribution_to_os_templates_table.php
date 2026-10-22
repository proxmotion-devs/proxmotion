<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('os_templates', function (Blueprint $table) {
            // A derivative's own os-release ID and version (pardus 23); family
            // and version then name the system it is based on (docs/adr/0022).
            $table->string('distribution', 32)->nullable()->after('version');
            $table->string('distribution_version', 32)->nullable()->after('distribution');
        });
    }

    public function down(): void
    {
        Schema::table('os_templates', function (Blueprint $table) {
            $table->dropColumn(['distribution', 'distribution_version']);
        });
    }
};
