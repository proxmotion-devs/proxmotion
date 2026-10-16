<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No client panel and no customer accounts (docs/adr/0007): servers carry
     * the billing system's ids instead of a panel user, panel users are staff.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->string('external_client_id')->nullable()->after('external_id')->index(); // billing customer id
        });

        Schema::dropIfExists('ssh_keys');

        // Customer accounts go away; tasks and audit logs keep their rows (user_id becomes null).
        DB::table('users')->where('role', 'client')->delete();

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 16)->default('client')->after('password')->index();
        });

        DB::table('users')->update(['role' => 'admin']);

        Schema::create('ssh_keys', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('public_key');
            $table->string('fingerprint', 64);
            $table->timestamps();

            $table->unique(['user_id', 'fingerprint']);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropIndex(['external_client_id']);
            $table->dropColumn('external_client_id');
            // Servers had an owner; the old owners cannot be restored.
            $table->foreignId('user_id')->nullable()->after('uuid')->constrained()->restrictOnDelete();
        });
    }
};
