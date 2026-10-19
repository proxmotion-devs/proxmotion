<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The operating systems administrators pick OS templates from: distributions
 * and their releases, editable in the panel instead of fixed in code
 * (docs/adr/0025). Filled with the list the panel shipped before.
 */
return new class extends Migration
{
    /**
     * os-release ID → name, family, base, same_version, releases (version → base version).
     *
     * @var array<string, array{0: string, 1: string, 2: string|null, 3: bool, 4: array<int|string, string|null>}>
     */
    private const array DEFAULTS = [
        'debian' => ['Debian', 'debian', null, false, ['13' => null, '12' => null, '11' => null]],
        'ubuntu' => ['Ubuntu', 'debian', null, false, ['26.04' => null, '24.04' => null, '22.04' => null, '20.04' => null]],
        'pardus' => ['Pardus', 'debian', 'debian', false, ['25' => '13', '23' => '12', '21' => '11']],
        'linuxmint' => ['Linux Mint', 'debian', 'ubuntu', false, ['22' => '24.04', '21' => '22.04']],
        'kali' => ['Kali Linux', 'debian', 'debian', false, ['rolling' => '13']],
        'almalinux' => ['AlmaLinux', 'rhel', null, false, ['10' => null, '9' => null, '8' => null]],
        'rocky' => ['Rocky Linux', 'rhel', null, false, ['10' => null, '9' => null, '8' => null]],
        'centos' => ['CentOS Stream', 'rhel', null, false, ['10' => null, '9' => null]],
        'fedora' => ['Fedora', 'rhel', null, false, ['43' => null, '42' => null]],
        'rhel' => ['Red Hat Enterprise Linux', 'rhel', 'almalinux', true, ['10' => null, '9' => null, '8' => null]],
        'ol' => ['Oracle Linux', 'rhel', 'almalinux', true, ['10' => null, '9' => null, '8' => null]],
        'opensuse-leap' => ['openSUSE Leap', 'suse', null, false, ['16.0' => null, '15.6' => null]],
        'opensuse-tumbleweed' => ['openSUSE Tumbleweed', 'suse', null, false, ['rolling' => null]],
        'arch' => ['Arch Linux', 'arch', null, false, ['rolling' => null]],
        'windows' => ['Windows', 'windows', null, false, ['2025' => null, '2022' => null, '2019' => null, '11' => null, '10' => null]],
    ];

    public function up(): void
    {
        Schema::create('distributions', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 32)->unique(); // os-release ID
            $table->string('name', 100);
            $table->string('family', 16);
            $table->string('base', 32)->nullable(); // slug of the distribution apps are matched as
            $table->boolean('same_version')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('distribution_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribution_id')->constrained()->cascadeOnDelete();
            $table->string('version', 32);
            $table->string('base_version', 32)->nullable();
            $table->timestamps();
            $table->unique(['distribution_id', 'version']);
        });

        $now = now();
        $order = 0;

        foreach (self::DEFAULTS as $slug => [$name, $family, $base, $sameVersion, $releases]) {
            $id = DB::table('distributions')->insertGetId([
                'slug' => $slug,
                'name' => $name,
                'family' => $family,
                'base' => $base,
                'same_version' => $sameVersion,
                'sort_order' => $order++,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($releases as $version => $baseVersion) {
                DB::table('distribution_releases')->insert([
                    'distribution_id' => $id,
                    'version' => (string) $version,
                    'base_version' => $baseVersion,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_releases');
        Schema::dropIfExists('distributions');
    }
};
