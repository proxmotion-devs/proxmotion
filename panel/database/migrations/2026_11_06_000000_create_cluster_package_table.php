<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The clusters a package's servers may be placed on. A package without
     * clusters may use any cluster of the ordered location.
     */
    public function up(): void
    {
        Schema::create('cluster_package', function (Blueprint $table) {
            $table->foreignId('cluster_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->primary(['cluster_id', 'package_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cluster_package');
    }
};
