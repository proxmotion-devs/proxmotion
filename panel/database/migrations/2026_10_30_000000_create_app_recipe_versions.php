<?php

use App\Enums\OsPlatform;
use App\Enums\TemplateCapability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recipe format v2 (docs/adr/0016): every version of a recipe is stored
     * and an installation points to the version it was installed with; OS
     * templates declare capabilities that recipes ask for.
     */
    public function up(): void
    {
        Schema::create('app_recipe_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_recipe_id')->constrained()->cascadeOnDelete();
            $table->string('version', 20);
            $table->unsignedTinyInteger('format');
            $table->json('definition');
            $table->longText('files');
            $table->char('checksum', 64);
            $table->text('signature')->nullable();
            $table->string('signed_by', 100)->nullable();
            $table->string('status', 20);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['app_recipe_id', 'version']);
        });

        Schema::table('app_recipes', function (Blueprint $table) {
            $table->foreignId('latest_version_id')->nullable()->after('source')->constrained('app_recipe_versions')->nullOnDelete();
        });

        Schema::table('app_installations', function (Blueprint $table) {
            $table->foreignId('app_recipe_version_id')->nullable()->after('app_recipe_id')->constrained('app_recipe_versions')->restrictOnDelete();
        });

        Schema::table('os_templates', function (Blueprint $table) {
            $table->json('capabilities')->nullable()->after('platform');
        });

        $now = now();

        foreach (DB::table('app_recipes')->get() as $recipe) {
            $definition = json_decode($recipe->definition, true);
            $versionId = DB::table('app_recipe_versions')->insertGetId([
                'app_recipe_id' => $recipe->id,
                'version' => $recipe->version,
                'format' => is_array($definition) && isset($definition['format']) ? (int) $definition['format'] : 1,
                'definition' => $recipe->definition,
                'files' => $recipe->files,
                'checksum' => $recipe->checksum,
                'status' => 'approved',
                'reviewed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('app_recipes')->where('id', $recipe->id)->update(['latest_version_id' => $versionId]);
            DB::table('app_installations')->where('app_recipe_id', $recipe->id)->update(['app_recipe_version_id' => $versionId]);
        }

        foreach (DB::table('os_templates')->get(['id', 'platform', 'family']) as $template) {
            DB::table('os_templates')->where('id', $template->id)->update([
                'capabilities' => json_encode(TemplateCapability::defaultsFor(OsPlatform::tryFrom((string) $template->platform) ?? OsPlatform::Linux, (string) $template->family)),
            ]);
        }

        Schema::table('app_recipes', function (Blueprint $table) {
            $table->dropColumn(['version', 'definition', 'files', 'checksum']);
        });

        Schema::table('app_installations', function (Blueprint $table) {
            $table->dropColumn('recipe_version');
        });
    }

    public function down(): void
    {
        Schema::table('app_recipes', function (Blueprint $table) {
            $table->string('version', 20)->default('0.0.0');
            $table->json('definition')->nullable();
            $table->longText('files')->nullable();
            $table->char('checksum', 64)->default(str_repeat('0', 64));
        });

        Schema::table('app_installations', function (Blueprint $table) {
            $table->string('recipe_version', 20)->default('0.0.0');
        });

        foreach (DB::table('app_recipe_versions')->whereIn('id', DB::table('app_recipes')->whereNotNull('latest_version_id')->pluck('latest_version_id'))->get() as $version) {
            DB::table('app_recipes')->where('id', $version->app_recipe_id)->update([
                'version' => $version->version,
                'definition' => $version->definition,
                'files' => $version->files,
                'checksum' => $version->checksum,
            ]);
        }

        foreach (DB::table('app_recipe_versions')->get(['id', 'version']) as $version) {
            DB::table('app_installations')->where('app_recipe_version_id', $version->id)->update(['recipe_version' => $version->version]);
        }

        Schema::table('app_installations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('app_recipe_version_id');
        });

        Schema::table('app_recipes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('latest_version_id');
        });

        Schema::table('os_templates', function (Blueprint $table) {
            $table->dropColumn('capabilities');
        });

        Schema::dropIfExists('app_recipe_versions');
    }
};
