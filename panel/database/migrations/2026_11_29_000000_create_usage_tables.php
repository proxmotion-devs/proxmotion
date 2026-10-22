<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Traffic quotas and usage limits (docs/adr/0039).
        Schema::table('servers', function (Blueprint $table) {
            // Proxmox's counters at the previous collection, for the next one's differences.
            $table->json('usage_counters')->nullable()->after('firewall_policy');
            // traffic or usage when the panel suspended the server itself; null otherwise.
            $table->string('suspension_cause', 16)->nullable()->after('suspension_reason');
            // Violations are recorded but never acted on.
            $table->boolean('usage_exempt')->default(false)->after('usage_counters');
        });

        // One row per server and collection (every 5 minutes), kept 48 hours.
        Schema::create('usage_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sampled_at');
            $table->string('status', 16);
            $table->float('cpu_percent')->nullable();
            $table->float('memory_percent')->nullable();
            // Bytes per second since the previous sample; null for the first one.
            $table->double('net_in_rate')->nullable();
            $table->double('net_out_rate')->nullable();
            $table->double('disk_read_rate')->nullable();
            $table->double('disk_write_rate')->nullable();

            $table->index(['server_id', 'sampled_at']);
            $table->index('sampled_at');
        });

        // Traffic per server and month (the period starts on the traffic reset day).
        Schema::create('traffic_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->unsignedBigInteger('in_bytes')->default(0);
            $table->unsignedBigInteger('out_bytes')->default(0);
            $table->timestamps();

            $table->unique(['server_id', 'period_start']);
        });

        Schema::create('usage_violations', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            // The traffic period of a traffic violation.
            $table->date('period_start')->nullable();
            // Counted bytes (traffic), else the average over the window in the limit's unit.
            $table->double('value');
            $table->double('threshold');
            $table->unsignedSmallInteger('window_minutes')->nullable();
            $table->string('action', 16);
            $table->timestamp('acted_at')->nullable();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_violations');
        Schema::dropIfExists('traffic_periods');
        Schema::dropIfExists('usage_samples');

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['usage_counters', 'suspension_cause', 'usage_exempt']);
        });
    }
};
