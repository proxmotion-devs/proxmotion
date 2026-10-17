<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uninstalls and actions of installed apps (docs/adr/0016 §4): each run
     * keeps its inputs, outcome and outputs (encrypted).
     */
    public function up(): void
    {
        Schema::create('app_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('app_installation_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('action', 40)->nullable();
            $table->string('status', 20);
            $table->text('inputs');
            $table->longText('outputs')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('step', 200)->nullable();
            $table->string('error', 1000)->nullable();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('api_token_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_runs');
    }
};
