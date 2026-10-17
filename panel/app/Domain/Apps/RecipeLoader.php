<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Proxmox\Resources\GuestAgentResource;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads and validates the recipes in panel/recipes (docs/adr/0015): each
 * directory not starting with "_" holds recipe.yaml, install.sh and the
 * action scripts recipe.yaml names.
 */
final class RecipeLoader
{
    /** Shared files the panel writes next to every recipe's install.sh. */
    public const LIBRARY_FILES = ['proxmotion.sh', 'run.sh'];

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
        $errors = $this->schemaErrors($data);

        if ($errors !== []) {
            throw new RecipeInvalid($slug, $errors);
        }

        $recipe = Recipe::fromArray($data);
        $files = [];
        $scripts = ['install.sh'];

        foreach (is_array($data['actions'] ?? null) ? $data['actions'] : [] as $action) {
            $scripts[] = is_array($action) ? (string) $action['script'] : '';
        }

        foreach ($scripts as $script) {
            $file = $path.'/'.$script;

            if (! is_file($file)) {
                $errors[] = "{$script} is missing.";

                continue;
            }

            $content = (string) file_get_contents($file);

            if (strlen($content) > GuestAgentResource::MAX_WRITE_BYTES) {
                $errors[] = "{$script} is larger than ".GuestAgentResource::MAX_WRITE_BYTES.' bytes.';
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
     * The shared helper and runner scripts (recipes/_lib).
     *
     * @return array<string, string>
     */
    public function library(): array
    {
        $files = [];

        foreach (self::LIBRARY_FILES as $name) {
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
        $validator = new Validator;
        $validator->setMaxErrors(20);

        $result = $validator->validate(
            json_decode((string) json_encode($data, JSON_THROW_ON_ERROR)),
            (string) file_get_contents($this->directory().'/_schema/recipe.schema.json'),
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
