<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Placement groups (docs/adr/0020): named sets of nodes, and optionally
     * their storages, that packages and new servers target.
     */
    public function up(): void
    {
        Schema::create('placement_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('node_placement_group', function (Blueprint $table) {
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('placement_group_id')->constrained()->cascadeOnDelete();
            $table->primary(['node_id', 'placement_group_id']);
        });

        // Empty: the nodes' storages marked "use for servers".
        Schema::create('placement_group_storage', function (Blueprint $table) {
            $table->foreignId('placement_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('storage_id')->constrained()->cascadeOnDelete();
            $table->primary(['placement_group_id', 'storage_id']);
        });

        // Deleting a group a package names is refused in the panel (docs/adr/0020 §5).
        Schema::create('package_placement_group', function (Blueprint $table) {
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('placement_group_id')->constrained()->restrictOnDelete();
            $table->primary(['package_id', 'placement_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_placement_group');
        Schema::dropIfExists('placement_group_storage');
        Schema::dropIfExists('node_placement_group');
        Schema::dropIfExists('placement_groups');
    }
};
