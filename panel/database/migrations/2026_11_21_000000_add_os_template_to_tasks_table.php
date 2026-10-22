<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // The OS template a create or reinstall installs: earlier runs with the same
            // template estimate how long a running one still takes (docs/adr/0032 §5).
            $table->foreignId('os_template_id')->nullable()->after('api_token_id')->constrained()->nullOnDelete();
            $table->index(['type', 'status', 'os_template_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['type', 'status', 'os_template_id']);
            $table->dropConstrainedForeignId('os_template_id');
        });
    }
};
