<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Proxmox\Resources\GuestAgentResource;
use Closure;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads and validates the recipes in panel/recipes (docs/adr/0015, 0016):
 * each directory not starting with "_" holds recipe.yaml and the scripts it
 * names (format 1: install.sh and actions; format 2: lifecycle and actions).
 */
final class RecipeLoader
{
    public function __construct(private readonly ?string $directory = null) {}

    public function directory(): string
    {
        return $this->directory ?? base_path('recipes');
    }

    /**
     * Every recipe, validated.
     *
     * @return array<string, array{recipe: Recipe, files: array<string, string>}> by slug
     *
     * @throws RecipeInvalid for the first recipe that does not validate
     */
    public function all(): array
    {
        $recipes = [];

        foreach ($this->slugs() as $slug) {
            $recipes[$slug] = $this->load($slug);
        }

        return $recipes;
    }

    /**
     * The slugs of the recipe directories.
     *
     * @return list<string>
     */
    public function slugs(): array
    {
        $slugs = [];

        foreach (glob($this->directory().'/*', GLOB_ONLYDIR) ?: [] as $path) {
            $slug = basename($path);

            if (! str_starts_with($slug, '_')) {
                $slugs[] = $slug;
            }
        }

        sort($slugs);

        return $slugs;
    }

    /**
     * Validate one recipe directory.
     *
     * @return array{recipe: Recipe, files: array<string, string>}
     *
     * @throws RecipeInvalid
     */
    public function load(string $slug): array
    {
        $path = $this->directory().'/'.$slug;
        $yaml = $path.'/recipe.yaml';

        if (! is_file($yaml)) {
            throw new RecipeInvalid($slug, ['recipe.yaml is missing.']);
        }

        try {
            $data = Yaml::parseFile($yaml);
        } catch (ParseException $e) {
            throw new RecipeInvalid($slug, ['recipe.yaml is not valid YAML: '.$e->getMessage()]);
        }

        if (! is_array($data)) {
            throw new RecipeInvalid($slug, ['recipe.yaml must be a mapping.']);
        }

        /** @var array<string, mixed> $data */
        return $this->check($slug, $data, fn (string $script): ?string => is_file($path.'/'.$script) ? (string) file_get_contents($path.'/'.$script) : null);
    }

    /**
     * Validate a recipe held in memory, e.g. a draft of the admin editor
     * (docs/adr/0018 §4), with the same rules as a directory.
     *
     * @param  array<string, mixed>  $data  the recipe.yaml as a mapping
     * @param  array<string, string>  $files  scripts and includes by path
     * @return array{recipe: Recipe, files: array<string, string>} only the files the recipe uses
     *
     * @throws RecipeInvalid
     */
    public function validate(string $slug, array $data, array $files): array
    {
        return $this->check($slug, $data, fn (string $script): ?string => $files[$script] ?? null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Closure(string): ?string  $read  the content of a script, null when it is missing
     * @return array{recipe: Recipe, files: array<string, string>}
     *
     * @throws RecipeInvalid
     */
    private function check(string $slug, array $data, Closure $read): array
    {
        $errors = $this->schemaErrors($data);

        if ($errors !== []) {
            throw new RecipeInvalid($slug, $errors);
        }

        $recipe = Recipe::fromArray($data);
        $files = [];

        foreach ($recipe->scripts() as $script) {
            if (str_ends_with($script, '.ps1') !== ($recipe->platform === 'windows')) {
                $errors[] = "{$script}: ".($recipe->platform === 'windows' ? 'Windows recipes use .ps1 scripts.' : 'Linux recipes use .sh scripts.');

                continue;
            }

            $content = $read($script);

            if ($content === null) {
                $errors[] = "{$script} is missing.";

                continue;
            }

            // A .ps1 file gets a byte order mark on its way into the server (AppGuest::content()).
            $limit = GuestAgentResource::MAX_WRITE_BYTES - (str_ends_with($script, '.ps1') && ! str_starts_with($content, "\u{FEFF}") ? 3 : 0);

            if (strlen($content) > $limit) {
                $errors[] = "{$script} is larger than {$limit} bytes.";
            }

            $files[$script] = $content;
        }

        $errors = [...$errors, ...$this->semanticErrors($slug, $recipe)];

        if ($errors !== []) {
            throw new RecipeInvalid($slug, $errors);
        }

        return ['recipe' => $recipe, 'files' => $files];
    }

    /**
     * The shared helper and runner scripts of a platform (recipes/_lib).
     *
     * @return array<string, string>
     */
    public function library(string $platform = 'linux'): array
    {
        $files = [];

        foreach (AppGuest::LIBRARY_FILES[$platform] ?? [] as $name) {
            $files[$name] = (string) file_get_contents($this->directory().'/_lib/'.$name);
        }

        return $files;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function schemaErrors(array $data): array
    {
        // Format 1 recipes have no format key (docs/adr/0015); format 2: docs/adr/0016.
        $schema = match ($data['format'] ?? 1) {
            1 => 'recipe.schema.json',
            2 => 'recipe-v2.schema.json',
            default => null,
        };

        if ($schema === null) {
            return ['format: this panel reads recipe formats 1 and 2.'];
        }

        $validator = new Validator;
        $validator->setMaxErrors(20);

        $result = $validator->validate(
            json_decode((string) json_encode($data, JSON_THROW_ON_ERROR)),
            (string) file_get_contents($this->directory().'/_schema/'.$schema),
        );

        $error = $result->error();

        if ($error === null) {
            return [];
        }

        $errors = [];

        foreach ((new ErrorFormatter)->format($error, false) as $pointer => $message) {
            $errors[] = ($pointer === '' || $pointer === '/' ? '' : $pointer.': ').(is_string($message) ? $message : json_encode($message));
        }

        return $errors;
    }

    /**
     * What the schema cannot express.
     *
     * @return list<string>
     */
    private function semanticErrors(string $slug, Recipe $recipe): array
    {
        $errors = [];

        if ($recipe->slug !== $slug) {
            $errors[] = "slug must be the directory name ({$slug}).";
        }

        $keys = array_map(fn (RecipeInput $input) => $input->key, $recipe->inputs);

        if (count($keys) !== count(array_unique($keys))) {
            $errors[] = 'Input keys must be unique.';
        }

        $outputs = array_map(fn (RecipeOutput $output) => $output->key, $recipe->outputs);

        if (count($outputs) !== count(array_unique($outputs))) {
            $errors[] = 'Output keys must be unique.';
        }

        // docs/adr/0016 §1: the installed versions the upgrade script accepts (only older ones are ever upgraded).
        if (isset($recipe->lifecycle['upgrade']) && ! VersionRange::isValid($recipe->lifecycle['upgrade']['from'] ?? '')) {
            $errors[] = 'lifecycle.upgrade.from is not a version range such as ">=1.0.0 <1.2.0".';
        }

        foreach ($recipe->inputs as $input) {
            if ($input->min !== null && $input->max !== null && $input->min > $input->max) {
                $errors[] = "Input {$input->key}: min is larger than max.";
            }

            if ($input->default !== null) {
                $problem = (new InputValidator)->problemWithDefault($input);

                if ($problem !== null) {
                    $errors[] = "Input {$input->key}: the default is invalid ({$problem}).";
                }
            }
        }

        $actions = array_map(fn (RecipeAction $action) => $action->key, $recipe->actions);

        if (count($actions) !== count(array_unique($actions))) {
            $errors[] = 'Action keys must be unique.';
        }

        foreach ($recipe->actions as $action) {
            $inputs = array_map(fn (RecipeInput $input) => $input->key, $action->inputs);
            $outputs = array_map(fn (RecipeOutput $output) => $output->key, $action->outputs);

            if (count($inputs) !== count(array_unique($inputs)) || count($outputs) !== count(array_unique($outputs))) {
                $errors[] = "Action {$action->key}: input and output keys must be unique.";
            }

            foreach ($action->inputs as $input) {
                $problem = $input->default === null ? null : (new InputValidator)->problemWithDefault($input);

                if ($problem !== null) {
                    $errors[] = "Action {$action->key}, input {$input->key}: the default is invalid ({$problem}).";
                }
            }
        }

        foreach (['min_versions' => $recipe->minVersions, 'max_versions' => $recipe->maxVersions] as $field => $versions) {
            foreach (array_keys($versions) as $distribution) {
                if (! $recipe->coversDistribution($distribution)) {
                    $errors[] = "requires.{$field}: {$distribution} is not covered by requires.os.";
                }
            }
        }

        foreach (is_array($recipe->definition['firewall'] ?? null) ? $recipe->definition['firewall'] : [] as $rule) {
            foreach (is_array($rule) ? $rule : [] as $value) {
                if (is_string($value) && preg_match('/^\{\{ inputs\.([a-z0-9_]+) \}\}$/', $value, $match) === 1 && $recipe->input($match[1]) === null) {
                    $errors[] = "Firewall rule refers to the unknown input {$match[1]}.";
                }
            }
        }

        return $errors;
    }
}
