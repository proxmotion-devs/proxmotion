<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rule sets administrators prepare for customers and packages (docs/adr/0035 §3).
        Schema::create('firewall_rulesets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            // What the rules do not match: accept, or drop (only what they allow comes in).
            $table->string('default_policy', 6)->default('drop');
            $table->json('rules');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // A server's inbound rules, in order (docs/adr/0035 §1).
        Schema::create('server_firewall_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('action', 6);
            $table->string('protocol', 5);
            $table->string('port', 11)->nullable();
            $table->string('source', 64)->nullable();
            $table->string('comment', 60)->nullable();
            // The SSH or RDP rule added when the firewall was turned on without rules (docs/adr/0035 §3).
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['server_id', 'position']);
        });

        Schema::table('servers', function (Blueprint $table) {
            // Incoming traffic no rule matches: accept (the default) or drop (docs/adr/0035 §1).
            $table->string('firewall_policy', 6)->default('accept')->after('firewall_checked_at');
        });

        Schema::table('packages', function (Blueprint $table) {
            // New servers start with these rules and their default policy.
            $table->foreignId('firewall_ruleset_id')->nullable()->after('disk_iops')->constrained('firewall_rulesets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('firewall_ruleset_id');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('firewall_policy');
        });

        Schema::dropIfExists('server_firewall_rules');
        Schema::dropIfExists('firewall_rulesets');
    }
};
