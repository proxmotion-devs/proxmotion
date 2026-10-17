<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Enums\NetworkModel;

/**
 * A validated recipe.yaml (docs/adr/0015, recipes/_schema/recipe.schema.json).
 */
final readonly class Recipe
{
    /**
     * @param  array{en: string, tr?: string}  $description
     * @param  list<array{family: string, versions: list<string>}>  $os
     * @param  list<string>  $networkModels
     * @param  list<string>  $installModes
     * @param  list<RecipeInput>  $inputs
     * @param  list<RecipeOutput>  $outputs
     * @param  array<string, mixed>  $definition  the whole recipe as read
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $version,
        public string $category,
        public array $description,
        public array $os,
        public int $minMemoryMib,
        public int $minDiskMib,
        public int $minCores,
        public array $networkModels,
        public array $installModes,
        public string $billing,
        public string $license,
        public array $inputs,
        public array $outputs,
        public int $timeoutMinutes,
        public array $definition,
    ) {}

    /**
     * @param  array<string, mixed>  $data  a recipe that passed the schema
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, int> $requirements */
        $requirements = $data['requirements'];
        $list = fn (string $key): array => array_values(array_map('strval', is_array($data[$key] ?? null) ? $data[$key] : []));
        $entries = fn (string $key): array => array_values(array_filter(is_array($data[$key] ?? null) ? $data[$key] : [], 'is_array'));

        $os = [];

        foreach ($entries('os') as $entry) {
            $os[] = ['family' => (string) $entry['family'], 'versions' => array_values(array_map('strval', (array) $entry['versions']))];
        }

        return new self(
            slug: (string) $data['slug'],
            name: (string) $data['name'],
            version: (string) $data['version'],
            category: (string) $data['category'],
            description: self::text($data['description']) ?? ['en' => ''],
            os: $os,
            minMemoryMib: (int) $requirements['min_memory_mib'],
            minDiskMib: (int) $requirements['min_disk_mib'],
            minCores: (int) $requirements['min_cores'],
            networkModels: $list('network_models'),
            installModes: $list('install_modes'),
            billing: (string) $data['billing'],
            license: (string) $data['license'],
            inputs: array_map(RecipeInput::fromArray(...), $entries('inputs')),
            outputs: array_map(RecipeOutput::fromArray(...), $entries('outputs')),
            timeoutMinutes: (int) $data['timeout_minutes'],
            definition: $data,
        );
    }

    /**
     * A translated text of recipe.yaml ({en, tr}).
     *
     * @return array{en: string, tr?: string}|null
     */
    public static function text(mixed $value): ?array
    {
        if (! is_array($value) || ! is_string($value['en'] ?? null)) {
            return null;
        }

        return is_string($value['tr'] ?? null) ? ['en' => $value['en'], 'tr' => $value['tr']] : ['en' => $value['en']];
    }

    public function input(string $key): ?RecipeInput
    {
        foreach ($this->inputs as $input) {
            if ($input->key === $key) {
                return $input;
            }
        }

        return null;
    }

    public function supportsOs(string $family, string $version): bool
    {
        foreach ($this->os as $entry) {
            if ($entry['family'] === strtolower($family) && in_array($version, $entry['versions'], true)) {
                return true;
            }
        }

        return false;
    }

    public function supportsNetworkModel(NetworkModel $model): bool
    {
        return in_array($model->value, $this->networkModels, true);
    }

    public function canInstallLater(): bool
    {
        return in_array('post', $this->installModes, true);
    }
}
