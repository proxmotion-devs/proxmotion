<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Domain\Infrastructure\Distributions;
use App\Enums\AppInstallMode;
use App\Enums\NetworkModel;
use App\Enums\OsFamily;
use App\Models\OsTemplate;

/**
 * A validated recipe.yaml (docs/adr/0015, 0016). Format 1 recipes (no
 * `format`) are read into the same shape as format 2: their `os` list
 * becomes the distributions and exact versions they support, install.sh
 * and `timeout_minutes` their install step.
 *
 * Operating systems (docs/adr/0024 §4): `requires.os` names families
 * (debian, rhel, …) and distributions (ubuntu, almalinux, …) separately.
 * The list form of older recipes is read with its old meaning: the group
 * names are families, other names distributions.
 */
final readonly class Recipe
{
    public const LIFECYCLE = ['install', 'uninstall', 'upgrade'];

    /** Package managers of recipes written before `os` (format 2 `capabilities`). */
    private const array LEGACY_CAPABILITIES = ['apt' => 'debian', 'dnf' => 'rhel', 'zypper' => 'suse', 'pacman' => 'arch'];

    /** Old list names that meant a whole family (archlinux and opensuse were alone in theirs). */
    private const array LEGACY_FAMILY_NAMES = ['archlinux' => 'arch', 'opensuse' => 'suse'];

    /**
     * @param  array{en: string, tr?: string}  $description
     * @param  list<array{family: string, versions: list<string>}>  $os  format 1 only: exact OS versions
     * @param  list<string>|null  $families  OS families it runs on (OsFamily values); null with $distributions: any OS of the platform
     * @param  list<string>|null  $distributions  single distributions (os-release IDs) it runs on
     * @param  array<string, string>  $minVersions  lowest version per distribution
     * @param  array<string, string>  $maxVersions  highest version per distribution
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
        public ?array $families,
        public ?array $distributions,
        public array $minVersions,
        public array $maxVersions,
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
            $families = [];
            $distributions = array_values(array_unique(array_map(Distributions::fromOldName(...), array_column($os, 'family'))));
            $minVersions = [];
            $maxVersions = [];
            $lifecycle = ['install' => ['script' => 'install.sh', 'timeout_minutes' => $timeout]];
        } else {
            /** @var array<string, mixed> $requires */
            $requires = $data['requires'];
            /** @var array<string, int> $resources */
            $resources = $data['resources'];
            $os = [];
            $platform = (string) $requires['platform'];
            [$families, $distributions] = self::operatingSystems($requires);
            $minVersions = self::versionLimits($requires['min_versions'] ?? null);
            $maxVersions = self::versionLimits($requires['max_versions'] ?? null);
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
            families: $families,
            distributions: $distributions,
            minVersions: $minVersions,
            maxVersions: $maxVersions,
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
     * The families and distributions of `requires`: `os` as an object
     * (docs/adr/0024 §4), or the list form of older recipes (`os`,
     * `families`, package-manager `capabilities`); [null, null]: any OS.
     *
     * @param  array<string, mixed>  $requires
     * @return array{0: list<string>|null, 1: list<string>|null}
     */
    private static function operatingSystems(array $requires): array
    {
        $strings = fn (mixed $value): array => array_values(array_map(fn ($item) => strtolower((string) $item), is_array($value) ? $value : []));
        $os = $requires['os'] ?? null;

        if (is_array($os) && ! array_is_list($os)) {
            return [$strings($os['families'] ?? null), $strings($os['distributions'] ?? null)];
        }

        if (is_array($os) || isset($requires['families'])) {
            $names = $strings($os ?? $requires['families']);
        } else {
            $names = [];

            foreach ($strings($requires['capabilities'] ?? null) as $capability) {
                foreach (explode('|', $capability) as $manager) {
                    if (isset(self::LEGACY_CAPABILITIES[$manager])) {
                        $names[] = self::LEGACY_CAPABILITIES[$manager];
                    }
                }
            }
        }

        if ($names === []) {
            return [null, null];
        }

        $families = [];
        $distributions = [];

        foreach ($names as $name) {
            $family = OsFamily::tryFrom(self::LEGACY_FAMILY_NAMES[$name] ?? $name);

            if ($family !== null) {
                $families[] = $family->value;
            } else {
                $distributions[] = $name;
            }
        }

        return [array_values(array_unique($families)), array_values(array_unique($distributions))];
    }

    /**
     * Version limits by distribution, old names (archlinux) as os-release IDs.
     *
     * @return array<string, string>
     */
    private static function versionLimits(mixed $limits): array
    {
        $result = [];

        foreach (is_array($limits) ? $limits : [] as $distribution => $version) {
            $result[Distributions::fromOldName((string) $distribution)] = (string) $version;
        }

        return $result;
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
     * Whether the recipe can run on servers of this template (docs/adr/0024 §4).
     */
    public function supportsTemplate(OsTemplate $template): bool
    {
        return $this->supports($template->platform->value, $template->distribution, $template->version, $template->compatible_with, $template->compatible_version);
    }

    /**
     * Whether the recipe runs on a distribution and version. A template with
     * a compatible distribution is matched as that one, unless the recipe
     * names its own distribution.
     */
    public function supports(string $platform, string $distribution, string $version, ?string $compatibleWith = null, ?string $compatibleVersion = null): bool
    {
        $own = Distributions::fromOldName($distribution);
        $match = $compatibleWith === null ? $own : Distributions::fromOldName($compatibleWith);
        $matchVersion = $compatibleWith === null ? $version : (string) $compatibleVersion;

        if ($platform !== $this->platform) {
            return false;
        }

        // Format 1: the exact distribution and version must be listed.
        if ($this->format === 1) {
            foreach ($this->os as $entry) {
                if (Distributions::fromOldName($entry['family']) === $match && in_array($matchVersion, $entry['versions'], true)) {
                    return true;
                }
            }

            return false;
        }

        if (! $this->coversDistribution($match) && ! in_array($own, $this->distributions ?? [], true)) {
            return false;
        }

        // The limits of its own distribution when the recipe names it, otherwise those of the one it is matched as.
        [$limitsOf, $compared] = isset($this->minVersions[$own]) || isset($this->maxVersions[$own]) ? [$own, $version] : [$match, $matchVersion];
        $minimum = $this->minVersions[$limitsOf] ?? null;
        $maximum = $this->maxVersions[$limitsOf] ?? null;

        return ($minimum === null || version_compare($compared, $minimum, '>='))
            && ($maximum === null || version_compare(self::versionParts($compared, $maximum), $maximum, '<='));
    }

    /**
     * Whether `requires.os` covers a distribution: any OS, the distribution
     * itself, or its family in the catalog.
     */
    public function coversDistribution(string $distribution): bool
    {
        if ($this->families === null && $this->distributions === null) {
            return true;
        }

        $family = Distributions::family($distribution);

        return in_array($distribution, $this->distributions ?? [], true)
            || ($family !== null && in_array($family->value, $this->families ?? [], true));
    }

    /**
     * The os-release ID / ID_LIKE values the runner accepts inside the
     * server (docs/adr/0016 §3): those of the families and the
     * distributions; empty: any.
     *
     * @return list<string>
     */
    public function osReleaseIds(): array
    {
        $ids = [];

        foreach ($this->families ?? [] as $family) {
            $ids = [...$ids, ...(OsFamily::tryFrom($family)?->osReleaseIds() ?? [])];
        }

        return array_values(array_unique([...$ids, ...($this->distributions ?? [])]));
    }

    /**
     * The version cut to as many parts as the limit has, so a maximum
     * of "12" covers "12.7" and one of "24.04" covers "24.04.1".
     */
    private static function versionParts(string $version, string $limit): string
    {
        return implode('.', array_slice(explode('.', $version), 0, substr_count($limit, '.') + 1));
    }

    public function supportsNetworkModel(NetworkModel $model): bool
    {
        return in_array($model->value, $this->networkModels, true);
    }

    public function allowsInstallMode(AppInstallMode $mode): bool
    {
        return in_array($mode->value, $this->installModes, true);
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
     * (format 1) or "linux: debian family, almalinux", "ubuntu ≥ 22.04" (format 2).
     *
     * @return list<string>
     */
    public function osSummary(): array
    {
        if ($this->format === 1) {
            return array_map(fn (array $entry) => $entry['family'].' '.implode(', ', $entry['versions']), $this->os);
        }

        $names = [
            ...array_map(fn (string $family) => $family.' family', $this->families ?? []),
            ...($this->distributions ?? []),
        ];
        $lines = [$this->platform.($names === [] ? '' : ': '.implode(', ', $names))];

        foreach ($this->minVersions as $distribution => $minimum) {
            $lines[] = $distribution.' ≥ '.$minimum;
        }

        foreach ($this->maxVersions as $distribution => $maximum) {
            $lines[] = $distribution.' ≤ '.$maximum;
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
