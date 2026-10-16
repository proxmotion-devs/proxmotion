<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guest scripts (docs/adr/0010): the platform of an OS template picks the
     * default scripts, and a template may override the script of an event.
     */
    public function up(): void
    {
        Schema::table('os_templates', function (Blueprint $table) {
            $table->string('platform', 16)->default('linux')->after('version');
        });

        DB::table('os_templates')->where('family', 'windows')->update(['platform' => 'windows']);

        Schema::create('guest_scripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('os_template_id')->constrained()->cascadeOnDelete();
            $table->string('event', 32);
            $table->text('content');
            $table->timestamps();

            $table->unique(['os_template_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_scripts');

        Schema::table('os_templates', function (Blueprint $table) {
            $table->dropColumn('platform');
        });
    }
};
