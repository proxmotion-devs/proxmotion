<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Storages administrators tick for backups (docs/adr/0051 §4); their content must include "backup".
        Schema::table('storages', function (Blueprint $table) {
            $table->boolean('use_for_backups')->default(false)->after('use_for_servers');
        });

        // The backups the panel took; Proxmox holds them on a backup storage.
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            // Where it is: restorable only from a node that reaches this storage.
            $table->foreignId('cluster_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->nullable()->constrained()->nullOnDelete();
            $table->string('storage', 100);
            // Proxmox's volume id, e.g. backup:backup/vzdump-qemu-100-2026_10_29-01_00_00.vma.zst.
            $table->string('volume');
            // The VMID it was taken of, which a move to another cluster may change: lists the storage by it.
            $table->unsignedInteger('vmid');
            $table->string('kind', 16)->default('manual');
            $table->string('label', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            // What the server was when it was taken: a restore brings it back.
            $table->foreignId('os_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hostname');
            $table->unsignedInteger('disk_mib');
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['server_id', 'volume']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');

        Schema::table('storages', function (Blueprint $table) {
            $table->dropColumn('use_for_backups');
        });
    }
};
