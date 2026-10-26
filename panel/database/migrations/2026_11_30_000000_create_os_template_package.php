<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The OS templates a package's servers may use; none means any
     * (docs/adr/0040). Deleting a template a package names is refused in the
     * panel, so a package never quietly allows every template.
     */
    public function up(): void
    {
        Schema::create('os_template_package', function (Blueprint $table) {
            $table->foreignId('os_template_id')->constrained()->restrictOnDelete();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->primary(['os_template_id', 'package_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('os_template_package');
    }
};
