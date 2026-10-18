<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rights to install paid apps on a server (docs/adr/0019): granted by an
     * order, the billing system or an administrator; revoked ones stay as history.
     */
    public function up(): void
    {
        Schema::create('app_entitlements', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_recipe_id')->constrained()->restrictOnDelete();
            $table->string('source', 10);
            $table->string('reference', 191)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('api_token_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'app_recipe_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_entitlements');
    }
};
