<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * App Marketplace (docs/adr/0015): recipes synced from panel/recipes and
     * the installations of apps on servers.
     */
    public function up(): void
    {
        Schema::create('app_recipes', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('source', 20);
            $table->string('name', 80);
            $table->string('version', 20);
            $table->string('category', 20);
            $table->json('definition');
            $table->longText('files');
            $table->char('checksum', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('app_installations', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_recipe_id')->constrained()->restrictOnDelete();
            $table->string('recipe_version', 20);
            $table->string('status', 20);
            $table->text('inputs');
            $table->longText('outputs')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('step', 200)->nullable();
            $table->string('error', 1000)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('api_token_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_installations');
        Schema::dropIfExists('app_recipes');
    }
};
