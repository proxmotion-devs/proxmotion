<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('os_templates', function (Blueprint $table) {
            // full or linked (docs/adr/0027); existing templates keep full clones.
            $table->string('clone_mode', 16)->default('full')->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('os_templates', function (Blueprint $table) {
            $table->dropColumn('clone_mode');
        });
    }
};
