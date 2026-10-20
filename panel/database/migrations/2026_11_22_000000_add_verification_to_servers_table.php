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
            // The latest checks from inside the server after a create, reinstall or
            // Check again: addresses, gateways, DNS, hostname, disk (docs/adr/0032 §6).
            $table->json('verification')->nullable()->after('provisioned_at');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('verification');
        });
    }
};
