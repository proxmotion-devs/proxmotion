<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Billing systems (docs/adr/0008): each API token belongs to one, and a
     * server to the one that ordered it, so several billing systems can share
     * a panel without seeing each other's servers, and service ids
     * (external_id) only have to be unique within one billing system.
     */
    public function up(): void
    {
        Schema::create('billing_systems', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::table('api_tokens', function (Blueprint $table) {
            $table->foreignId('billing_system_id')->nullable()->after('name')->constrained()->restrictOnDelete();
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->foreignId('billing_system_id')->nullable()->after('package_id')->constrained()->restrictOnDelete();
            $table->dropUnique(['external_id']);
            $table->unique(['billing_system_id', 'external_id']);
        });

        // Existing tokens and billing ids (development installs only) move to one billing system.
        if (DB::table('api_tokens')->exists() || DB::table('servers')->whereNotNull('external_id')->exists()) {
            $id = DB::table('billing_systems')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'name' => 'Billing system',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('api_tokens')->update(['billing_system_id' => $id]);
            DB::table('servers')->whereNotNull('external_id')->update(['billing_system_id' => $id]);
        }

        // Every token belongs to a billing system from now on.
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->foreignId('billing_system_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropUnique(['billing_system_id', 'external_id']);
            $table->dropConstrainedForeignId('billing_system_id');
            $table->unique('external_id');
        });

        Schema::table('api_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_system_id');
        });

        Schema::dropIfExists('billing_systems');
    }
};
