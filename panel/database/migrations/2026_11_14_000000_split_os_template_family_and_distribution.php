<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OS templates name their own distribution and version; the family is the
 * real family (debian, rhel, …) and a derivative names the distribution it
 * is compatible with (docs/adr/0024 §7).
 */
return new class extends Migration
{
    /** Template families before docs/adr/0024 → distribution and family. */
    private const array OLD_FAMILIES = [
        'ubuntu' => ['ubuntu', 'debian'],
        'debian' => ['debian', 'debian'],
        'almalinux' => ['almalinux', 'rhel'],
        'rocky' => ['rocky', 'rhel'],
        'centos' => ['centos', 'rhel'],
        'fedora' => ['fedora', 'rhel'],
        'opensuse' => ['opensuse-leap', 'suse'],
        'archlinux' => ['arch', 'arch'],
        'windows' => ['windows', 'windows'],
    ];

    /** Derivatives the panel knew before, with their family. */
    private const array DERIVATIVE_FAMILIES = ['pardus' => 'debian', 'linuxmint' => 'debian', 'kali' => 'debian', 'rhel' => 'rhel', 'ol' => 'rhel'];

    public function up(): void
    {
        Schema::table('os_templates', function (Blueprint $table) {
            $table->string('compatible_with', 32)->nullable()->after('distribution');
            $table->string('compatible_version', 32)->nullable()->after('compatible_with');
        });

        foreach (DB::table('os_templates')->get() as $template) {
            $old = self::OLD_FAMILIES[$template->family] ?? null;

            if ($template->distribution === null) {
                [$distribution, $family] = $old ?? ['linux', 'other'];
                $values = ['distribution' => $distribution, 'family' => $family];
            } elseif ($old !== null) {
                // A derivative (docs/adr/0022): its base becomes what it is compatible with.
                $values = [
                    'family' => self::DERIVATIVE_FAMILIES[$template->distribution] ?? $old[1],
                    'version' => $template->distribution_version ?? $template->version,
                    'compatible_with' => $old[0],
                    'compatible_version' => $template->version,
                ];
            } else {
                // A derivative of an unknown base: no apps, as before.
                $values = ['family' => 'other', 'version' => $template->distribution_version ?? $template->version];
            }

            DB::table('os_templates')->where('id', $template->id)->update($values);
        }

        Schema::table('os_templates', function (Blueprint $table) {
            $table->dropColumn('distribution_version');
        });

        Schema::table('os_templates', function (Blueprint $table) {
            $table->string('distribution', 32)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('os_templates', function (Blueprint $table) {
            $table->string('distribution', 32)->nullable()->change();
            $table->string('distribution_version', 32)->nullable()->after('distribution');
        });

        $families = [];

        foreach (self::OLD_FAMILIES as $old => [$distribution]) {
            $families[$distribution] = $old;
        }

        foreach (DB::table('os_templates')->get() as $template) {
            if ($template->compatible_with !== null) {
                $values = [
                    'family' => $families[$template->compatible_with] ?? 'other',
                    'version' => $template->compatible_version ?? $template->version,
                    'distribution_version' => $template->version,
                ];
            } elseif (isset($families[$template->distribution])) {
                $values = ['family' => $families[$template->distribution], 'distribution' => null];
            } else {
                $values = ['family' => 'other', 'distribution' => $template->distribution === 'linux' ? null : $template->distribution, 'distribution_version' => $template->distribution === 'linux' ? null : $template->version];
            }

            DB::table('os_templates')->where('id', $template->id)->update($values);
        }

        Schema::table('os_templates', function (Blueprint $table) {
            $table->dropColumn(['compatible_with', 'compatible_version']);
        });
    }
};
