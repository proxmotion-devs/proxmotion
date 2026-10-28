<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Usage history (docs/adr/0050).
        Schema::table('usage_samples', function (Blueprint $table) {
            // Bytes since the previous sample; null for the first one.
            $table->unsignedBigInteger('net_in_bytes')->nullable()->after('net_out_rate');
            $table->unsignedBigInteger('net_out_bytes')->nullable()->after('net_in_bytes');
        });

        // One row per server and hour or day, from the samples; kept 35 and 400 days.
        Schema::create('usage_rollups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('resolution', 4);
            $table->timestamp('starts_at');
            $table->unsignedInteger('samples');
            $table->float('cpu_percent')->nullable();
            $table->float('memory_percent')->nullable();
            // Bytes per second, averaged over the samples.
            $table->double('net_in_rate')->nullable();
            $table->double('net_out_rate')->nullable();
            $table->double('disk_read_rate')->nullable();
            $table->double('disk_write_rate')->nullable();
            $table->unsignedBigInteger('in_bytes')->default(0);
            $table->unsignedBigInteger('out_bytes')->default(0);

            $table->unique(['server_id', 'resolution', 'starts_at']);
            $table->index(['resolution', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_rollups');

        Schema::table('usage_samples', function (Blueprint $table) {
            $table->dropColumn(['net_in_bytes', 'net_out_bytes']);
        });
    }
};
