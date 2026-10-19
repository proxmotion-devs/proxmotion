<?php

declare(strict_types=1);

use App\Models\AppRecipeVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operating systems and their versions are the administrators' own list,
 * and administrators tick the versions each app is offered on
 * (docs/adr/0026). The systems and versions of existing OS templates are
 * moved into the list, and every app keeps the templates it was offered on.
 */
return new class extends Migration
{
    /** Names for the os-release IDs templates used so far. */
    private const array NAMES = [
        'debian' => 'Debian', 'ubuntu' => 'Ubuntu', 'pardus' => 'Pardus', 'linuxmint' => 'Linux Mint',
        'kali' => 'Kali Linux', 'almalinux' => 'AlmaLinux', 'rocky' => 'Rocky Linux', 'centos' => 'CentOS Stream',
        'fedora' => 'Fedora', 'rhel' => 'Red Hat Enterprise Linux', 'ol' => 'Oracle Linux',
        'opensuse-leap' => 'openSUSE Leap', 'opensuse-tumbleweed' => 'openSUSE Tumbleweed', 'arch' => 'Arch Linux',
        'windows' => 'Windows',
    ];

    public function up(): void
    {
        // An earlier, unreleased form of this list (docs/adr/0025).
        Schema::dropIfExists('distribution_releases');
        Schema::dropIfExists('distributions');

        Schema::create('operating_systems', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 32)->unique();
            $table->string('platform', 16);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('operating_system_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operating_system_id')->constrained()->cascadeOnDelete();
            $table->string('version', 32);
            $table->timestamps();
            $table->unique(['operating_system_id', 'version']);
        });

        // The versions an app is offered on.
        Schema::create('app_recipe_operating_system_version', function (Blueprint $table) {
            $table->foreignId('app_recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operating_system_version_id')->constrained()->cascadeOnDelete();
            $table->primary(['app_recipe_id', 'operating_system_version_id']);
        });

        Schema::table('os_templates', function (Blueprint $table) {
            $table->foreignId('operating_system_version_id')->nullable()->after('id')->constrained()->restrictOnDelete();
        });

        $this->moveTemplates();

        Schema::table('os_templates', function (Blueprint $table) {
            $table->dropColumn(['compatible_with', 'compatible_version']);
        });
    }

    private function moveTemplates(): void
    {
        $now = now();
        $systems = [];
        $versions = [];

        foreach (DB::table('os_templates')->orderBy('sort_order')->orderBy('id')->get() as $template) {
            $slug = (string) $template->distribution;

            $systems[$slug] ??= DB::table('operating_systems')->insertGetId([
                'name' => self::NAMES[$slug] ?? ucfirst($slug),
                'slug' => $slug,
                'platform' => $template->platform,
                'sort_order' => count($systems),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $key = $slug.' '.$template->version;
            $versions[$key] ??= DB::table('operating_system_versions')->insertGetId([
                'operating_system_id' => $systems[$slug],
                'version' => $template->version,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('os_templates')->where('id', $template->id)->update(['operating_system_version_id' => $versions[$key]]);
            $this->tickApps($template, $versions[$key]);
        }
    }

    /** The apps offered on the template so far (docs/adr/0024 §4) are ticked for its version. */
    private function tickApps(stdClass $template, int $versionId): void
    {
        $apps = DB::table('app_recipes')->whereNotNull('latest_version_id')->pluck('latest_version_id', 'id');

        foreach ($apps as $appId => $latestId) {
            $recipe = AppRecipeVersion::query()->whereKey($latestId)->first()?->recipe();
            $fits = $recipe?->suggests(
                (string) $template->platform,
                (string) $template->distribution,
                (string) $template->version,
                $template->compatible_with,
                $template->compatible_version,
            );

            if ($fits === true) {
                DB::table('app_recipe_operating_system_version')->insertOrIgnore(['app_recipe_id' => $appId, 'operating_system_version_id' => $versionId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('os_templates', function (Blueprint $table) {
            $table->string('compatible_with', 32)->nullable()->after('distribution');
            $table->string('compatible_version', 32)->nullable()->after('compatible_with');
            $table->dropConstrainedForeignId('operating_system_version_id');
        });

        Schema::dropIfExists('app_recipe_operating_system_version');
        Schema::dropIfExists('operating_system_versions');
        Schema::dropIfExists('operating_systems');
    }
};
