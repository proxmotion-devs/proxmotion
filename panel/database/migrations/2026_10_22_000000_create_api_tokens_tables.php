<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tokens of the billing API and the idempotency keys of their write
     * requests (docs/adr/0008). Tasks and audit log entries can now name the
     * token that started them instead of an administrator.
     */
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('name');
            $table->char('token_hash', 64)->unique(); // SHA-256 of the secret
            $table->string('token_prefix', 16); // shown to admins to tell tokens apart
            $table->json('scopes');
            $table->json('allowed_ips')->nullable(); // addresses or CIDRs; null = any
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_token_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('status_code')->nullable(); // null while the first request runs
            $table->longText('response')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->unique(['api_token_id', 'key']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('api_token_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('api_token_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('api_token_id');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('api_token_id');
        });

        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('api_tokens');
    }
};
