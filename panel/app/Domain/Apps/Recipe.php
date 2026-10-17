<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Enums\NetworkModel;
use App\Models\OsTemplate;

/**
 * A validated recipe.yaml (docs/adr/0015, 0016). Format 1 recipes (no
 * `format`) are read into the same shape as format 2: their `os` list
 * becomes the families and exact versions they support, install.sh and
 * `timeout_minutes` their install step.
 */
final readonly class Recipe
{
    public const LIFECYCLE = ['install', 'uninstall', 'upgrade'];

    /**
     * @param  array{en: string, tr?: string}  $description
     * @param  list<array{family: string, versions: list<string>}>  $os  format 1 only: exact OS versions
     * @param  list<string>  $capabilities  required template capabilities; "apt|dnf" means one of them
     * @param  list<string>|null  $families  null: any family with the capabilities
     * @param  array<string, string>  $minVersions  lowest version per family
     * @param  list<string>  $networkModels
     * @param  list<string>  $installModes
     * @param  list<RecipeInput>  $inputs
     * @param  list<RecipeOutput>  $outputs
     * @param  array<string, array{script: string, timeout_minutes: int, from?: string}>  $lifecycle  by operation
     * @param  list<RecipeAction>  $actions
     * @param  list<string>  $includes  shared files the scripts source (format 2)
     * @param  array<string, mixed>  $definition  the whole recipe as read
     */
    public function __construct(
        public int $format,
        public string $slug,
        public string $name,
        public string $version,
        public string $category,
        public array $description,
        public string $platform,
        public array $os,
        public array $capabilities,
        public ?array $families,
        public array $minVersions,
        public int $minMemoryMib,
        public int $minDiskMib,
        public int $minCores,
        public array $networkModels,
        public array $installModes,
        public string $billing,
        public string $license,
        public array $inputs,
        public array $outputs,
        public array $lifecycle,
        public array $actions,
        public array $includes,
        public int $timeoutMinutes,
        public array $definition,
    ) {}

    /**
     * @param  array<string, mixed>  $data  a recipe that passed its schema
     */
    public static function fromArray(array $data): self
    {
        $format = (int) ($data['format'] ?? 1);
        $list = fn (mixed $value): array => array_values(array_map('strval', is_array($value) ? $value : []));
        $entries = fn (mixed $value): array => array_values(array_filter(is_array($value) ? $value : [], 'is_array'));

        if ($format === 1) {
            $os = [];

            foreach ($entries($data['os'] ?? null) as $entry) {
                $os[] = ['family' => (string) $entry['family'], 'versions' => $list($entry['versions'] ?? null)];
            }

            /** @var array<string, int> $resources */
            $resources = $data['requirements'];
            $timeout = (int) $data['timeout_minutes'];
            $platform = 'linux';
            $capabilities = [];
            $families = array_values(array_unique(array_column($os, 'family')));
            $minVersions = [];
            $lifecycle = ['install' => ['script' => 'install.sh', 'timeout_minutes' => $timeout]];
        } else {
            /** @var array<string, mixed> $requires */
            $requires = $data['requires'];
            /** @var array<string, int> $resources */
            $resources = $data['resources'];
            $os = [];
            $platform = (string) $requires['platform'];
            $capabilities = $list($requires['capabilities'] ?? null);
            $families = isset($requires['families']) ? $list($requires['families']) : null;
            $minVersions = array_map('strval', is_array($requires['min_versions'] ?? null) ? $requires['min_versions'] : []);
            $lifecycle = [];

            foreach (is_array($data['lifecycle'] ?? null) ? $data['lifecycle'] : [] as $operation => $step) {
                if (is_array($step) && in_array($operation, self::LIFECYCLE, true)) {
                    $lifecycle[(string) $operation] = array_filter([
                        'script' => (string) $step['script'],
                        'timeout_minutes' => (int) $step['timeout_minutes'],
                        'from' => isset($step['from']) ? (string) $step['from'] : null,
                    ], fn ($value) => $value !== null);
                }
            }

            $timeout = $lifecycle['install']['timeout_minutes'] ?? 15;
        }

        /** @var array<string, array{script: string, timeout_minutes: int, from?: string}> $lifecycle */
        return new self(
            format: $format,
            slug: (string) $data['slug'],
            name: (string) $data['name'],
            version: (string) $data['version'],
            category: (string) $data['category'],
            description: self::text($data['description']) ?? ['en' => ''],
            platform: $platform,
            os: $os,
            capabilities: $capabilities,
            families: $families,
            minVersions: $minVersions,
            minMemoryMib: (int) $resources['min_memory_mib'],
            minDiskMib: (int) $resources['min_disk_mib'],
            minCores: (int) $resources['min_cores'],
            networkModels: $list($data['network_models'] ?? null),
            installModes: $list($data['install_modes'] ?? null),
            billing: (string) $data['billing'],
            license: (string) $data['license'],
            inputs: array_map(RecipeInput::fromArray(...), $entries($data['inputs'] ?? null)),
            outputs: array_map(RecipeOutput::fromArray(...), $entries($data['outputs'] ?? null)),
            lifecycle: $lifecycle,
            actions: array_map(RecipeAction::fromArray(...), $entries($data['actions'] ?? null)),
            includes: $list($data['includes'] ?? null),
            timeoutMinutes: $timeout,
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

    /**
     * Whether the recipe can run on servers of this template (docs/adr/0016 §3).
     */
    public function supportsTemplate(OsTemplate $template): bool
    {
        return $this->supports($template->platform->value, $template->family, $template->version, $template->offeredCapabilities());
    }

    /**
     * @param  list<string>  $capabilities  what the template offers
     */
    public function supports(string $platform, string $family, string $version, array $capabilities): bool
    {
        $family = strtolower($family);

        if ($platform !== $this->platform) {
            return false;
        }

        // Format 1: the exact family and version must be listed.
        if ($this->format === 1) {
            foreach ($this->os as $entry) {
                if ($entry['family'] === $family && in_array($version, $entry['versions'], true)) {
                    return true;
                }
            }

            return false;
        }

        foreach ($this->capabilities as $required) {
            if (array_intersect(explode('|', $required), $capabilities) === []) {
                return false;
            }
        }

        if ($this->families !== null && ! in_array($family, $this->families, true)) {
            return false;
        }

        $minimum = $this->minVersions[$family] ?? null;

        return $minimum === null || version_compare($version, $minimum, '>=');
    }

    public function supportsNetworkModel(NetworkModel $model): bool
    {
        return in_array($model->value, $this->networkModels, true);
    }

    public function canInstallLater(): bool
    {
        return in_array('post', $this->installModes, true);
    }

    /**
     * The script path of a lifecycle operation (install, uninstall, upgrade), if declared.
     */
    public function lifecycleScript(string $operation): ?string
    {
        return $this->lifecycle[$operation]['script'] ?? null;
    }

    /**
     * The operating systems in short, for lists: "ubuntu 22.04, 24.04"
     * (format 1) or "linux: systemd, apt|dnf; ubuntu ≥ 22.04" (format 2).
     *
     * @return list<string>
     */
    public function osSummary(): array
    {
        if ($this->format === 1) {
            return array_map(fn (array $entry) => $entry['family'].' '.implode(', ', $entry['versions']), $this->os);
        }

        $lines = [$this->platform.($this->capabilities === [] ? '' : ': '.implode(', ', $this->capabilities))];

        foreach ($this->families ?? array_keys($this->minVersions) as $family) {
            $lines[] = $family.(isset($this->minVersions[$family]) ? ' ≥ '.$this->minVersions[$family] : '');
        }

        return $lines;
    }

    /**
     * Every file of the recipe besides recipe.yaml: lifecycle and action scripts and includes.
     *
     * @return list<string>
     */
    public function scripts(): array
    {
        $scripts = [
            ...array_column($this->lifecycle, 'script'),
            ...array_map(fn (RecipeAction $action) => $action->script, $this->actions),
            ...$this->includes,
        ];

        return array_values(array_unique($scripts));
    }

    public function action(string $key): ?RecipeAction
    {
        foreach ($this->actions as $action) {
            if ($action->key === $key) {
                return $action;
            }
        }

        return null;
    }

    public function canUninstall(): bool
    {
        return $this->lifecycleScript('uninstall') !== null;
    }
}
