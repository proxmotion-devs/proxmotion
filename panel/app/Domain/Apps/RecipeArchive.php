<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Models\AppRecipeVersion;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use ZipArchive;

/**
 * A recipe as a zip of its format 2 directory (docs/adr/0018 §5): the same
 * layout as panel/recipes/<slug> (recipe.yaml and its scripts), inside a
 * folder named after the slug. Imports are read entry by entry in memory,
 * never extracted to disk, and refused when anything looks off: paths
 * outside the recipe, links, unknown files, too many or too large files.
 */
final readonly class RecipeArchive
{
    /** At most this large an uploaded zip, in KiB (the upload rule). */
    public const MAX_ZIP_KIB = 1024;

    public const MAX_ENTRIES = 60;

    /** recipe.yaml, like the editor's YAML field. */
    public const MAX_DEFINITION_BYTES = 65536;

    /** One script, like the editor's file fields (one guest agent write). */
    public const MAX_FILE_BYTES = 46080;

    /** Files in the archive that are not part of a stored version and are skipped. */
    private const IGNORED = ['README.md'];

    /**
     * The version as zip bytes.
     */
    public function export(AppRecipeVersion $version): string
    {
        $slug = $version->app->slug;
        // ZipArchive writes to a file. The panel's own storage, not the system temp
        // directory, which the web server's PHP may not be allowed to write to.
        $directory = storage_path('framework/recipes');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.Str::uuid()->toString().'.zip';

        try {
            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The recipe archive could not be created.');
            }

            $zip->addFromString($slug.'/recipe.yaml', Yaml::dump($version->definition, 6, 4));

            foreach ($version->files as $file => $content) {
                $zip->addFromString($slug.'/'.$file, $content);
            }

            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * The recipe definition and files of an uploaded zip, not validated as a
     * recipe yet (RecipeEditor does that like for any draft).
     *
     * @return array{definition: array<string, mixed>, files: array<string, string>}
     *
     * @throws ValidationException under `archive`
     */
    public function read(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw self::refused('The file is not a zip archive.');
        }

        try {
            return $this->entries($zip);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{definition: array<string, mixed>, files: array<string, string>}
     *
     * @throws ValidationException
     */
    private function entries(ZipArchive $zip): array
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw self::refused('The archive has more than :count entries.', ['count' => (string) self::MAX_ENTRIES]);
        }

        $names = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                throw self::refused('The archive could not be read.');
            }

            $name = $stat['name'];

            if (str_ends_with($name, '/')) {
                continue;
            }

            if (str_contains($name, '\\') || str_starts_with($name, '/') || in_array('..', explode('/', $name), true) || preg_match('/[\x00-\x1f]/', $name) === 1) {
                throw self::refused('The archive has a file outside the recipe: :path', ['path' => Str::limit($name, 80)]);
            }

            if ($this->isLink($zip, $index)) {
                throw self::refused('The archive has a link: :path', ['path' => Str::limit($name, 80)]);
            }

            $names[$index] = $name;
        }

        $prefix = self::commonFolder($names);
        $definition = null;
        $files = [];

        foreach ($names as $index => $name) {
            $relative = substr($name, strlen($prefix));

            if (in_array($relative, self::IGNORED, true)) {
                continue;
            }

            if ($relative === 'recipe.yaml') {
                $definition = $this->yaml($this->content($zip, $index, $relative, self::MAX_DEFINITION_BYTES));

                continue;
            }

            if (preg_match(RecipeEditor::FILE_PATH, $relative) !== 1) {
                throw self::refused('The archive has a file a recipe cannot have: :path (scripts end in .sh or .ps1).', ['path' => Str::limit($relative, 80)]);
            }

            $files[$relative] = $this->content($zip, $index, $relative, self::MAX_FILE_BYTES);
        }

        if ($definition === null) {
            throw self::refused('The archive has no recipe.yaml.');
        }

        ksort($files);

        return ['definition' => $definition, 'files' => $files];
    }

    /**
     * Read one entry, never more than $limit bytes, whatever its header claims.
     *
     * @throws ValidationException
     */
    private function content(ZipArchive $zip, int $index, string $path, int $limit): string
    {
        $stat = $zip->statIndex($index);

        if ($stat === false || $stat['size'] > $limit) {
            throw self::refused('The file :path is larger than :size KiB.', ['path' => $path, 'size' => (string) intdiv($limit, 1024)]);
        }

        $content = $zip->getFromIndex($index, $limit + 1);

        if ($content === false) {
            throw self::refused('The archive could not be read.');
        }

        if (strlen($content) > $limit) {
            throw self::refused('The file :path is larger than :size KiB.', ['path' => $path, 'size' => (string) intdiv($limit, 1024)]);
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            throw self::refused('The file :path is not UTF-8 text.', ['path' => $path]);
        }

        // Scripts written on Windows; bash needs LF.
        return str_replace("\r\n", "\n", $content);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function yaml(string $text): array
    {
        try {
            $definition = Yaml::parse($text);
        } catch (ParseException $e) {
            throw self::refused('recipe.yaml is not valid YAML: :error', ['error' => $e->getMessage()]);
        }

        if (! is_array($definition) || array_is_list($definition)) {
            throw self::refused('recipe.yaml must be a mapping.');
        }

        /** @var array<string, mixed> $definition */
        return $definition;
    }

    /**
     * A symbolic link stored with Unix attributes.
     */
    private function isLink(ZipArchive $zip, int $index): bool
    {
        $system = 0;
        $attributes = 0;

        if (! $zip->getExternalAttributesIndex($index, $system, $attributes)) {
            return false;
        }

        return $system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000;
    }

    /**
     * The one folder every file is in (e.g. "wireguard/"), or "" when the
     * files are at the top of the archive.
     *
     * @param  array<int, string>  $names
     */
    private static function commonFolder(array $names): string
    {
        $folders = array_unique(array_map(fn (string $name) => str_contains($name, '/') ? strstr($name, '/', true).'/' : '', $names));

        if (count($folders) !== 1 || reset($folders) === '') {
            return '';
        }

        $folder = (string) reset($folders);

        // A recipe at the top whose only files are in one folder keeps it (actions/ alone has no recipe.yaml).
        return in_array($folder.'recipe.yaml', $names, true) ? $folder : '';
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function refused(string $message, array $replace = []): ValidationException
    {
        return ValidationException::withMessages(['archive' => __($message, $replace)]);
    }
}
