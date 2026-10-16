<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reverse DNS through pluggable drivers (docs/adr/0003).
     */
    public function up(): void
    {
        Schema::create('dns_providers', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('name', 100);
            $table->string('driver', 32);
            $table->json('options')->nullable();
            // Encrypted (API keys); never sent to the browser.
            $table->text('credentials')->nullable();
            $table->timestamps();
        });

        Schema::table('ip_pools', function (Blueprint $table) {
            $table->foreignId('dns_provider_id')->nullable()->after('nameservers')->constrained()->nullOnDelete();
            $table->string('reverse_zone')->nullable()->after('dns_provider_id');
            $table->boolean('rdns_require_forward')->default(false)->after('reverse_zone');
        });

        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->string('ptr', 253)->nullable()->after('notes');
            // Null while the PTR still has to be published (or exported by hand).
            $table->timestamp('ptr_synced_at')->nullable()->after('ptr');
        });
    }

    public function down(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->dropColumn(['ptr', 'ptr_synced_at']);
        });

        Schema::table('ip_pools', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dns_provider_id');
            $table->dropColumn(['reverse_zone', 'rdns_require_forward']);
        });

        Schema::dropIfExists('dns_providers');
    }
};
