<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Apps chosen when the server is ordered or reinstalled start after the
     * guest agent answers (docs/adr/0016 §5); the others were installed later.
     */
    public function up(): void
    {
        Schema::table('app_installations', function (Blueprint $table) {
            $table->string('mode', 10)->default('post')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('app_installations', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
