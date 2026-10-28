<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The App Store was removed (docs/adr/0042): apps come as .proxapp packages.
     */
    public function up(): void
    {
        Schema::dropIfExists('app_catalog_entries');
        Schema::dropIfExists('app_catalog_sources');
    }

    public function down(): void
    {
        Schema::create('app_catalog_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->string('repository', 500);
            $table->string('ref', 120);
            $table->char('commit', 40)->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('app_catalog_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_catalog_source_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 50);
            $table->string('version', 20)->nullable();
            $table->string('name', 80)->nullable();
            $table->string('category', 20)->nullable();
            $table->string('platform', 10)->nullable();
            $table->json('definition')->nullable();
            $table->longText('files')->nullable();
            $table->char('checksum', 64)->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->unique(['app_catalog_source_id', 'slug']);
        });
    }
};
