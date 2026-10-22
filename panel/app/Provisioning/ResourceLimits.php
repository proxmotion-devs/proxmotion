<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Models\Package;
use App\Models\Server;
use App\Proxmox\Data\BootDisk;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Limits that keep one server from taking CPU time and disk throughput from
 * the others on its node (docs/adr/0034). Null is unlimited. They come from
 * the package or, for custom servers, from the admin forms; the billing API
 * takes no overrides.
 */
final readonly class ResourceLimits
{
    /** Disk throttling options Proxmox reads from a drive setting. */
    private const DISK_OPTIONS = ['mbps_rd', 'mbps_wr', 'iops_rd', 'iops_wr'];

    public function __construct(
        /** At most this many cores' worth of CPU time (Proxmox `cpulimit`), even with more vCPUs. */
        public ?float $cpuLimit = null,
        /** Relative CPU weight when the node is busy (Proxmox `cpuunits`, default 100 with cgroup v2). */
        public ?int $cpuUnits = null,
        /** Read and write throughput of the boot disk, MB/s each (`mbps_rd`, `mbps_wr`). */
        public ?int $diskMbS = null,
        /** Read and write operations per second of the boot disk, each (`iops_rd`, `iops_wr`). */
        public ?int $diskIops = null,
    ) {}

    public static function of(Package|Server $model): self
    {
        return new self(
            cpuLimit: $model->cpu_limit === null ? null : (float) $model->cpu_limit,
            cpuUnits: $model->cpu_units,
            diskMbS: $model->disk_mb_s,
            diskIops: $model->disk_iops,
        );
    }

    /**
     * Validation of the form fields, the same in every admin form.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'cpu_limit' => ['nullable', 'numeric', 'between:0.1,512', 'decimal:0,2'],
            // cgroup v2 takes 1–10000 (v1 up to 262144); stay within both.
            'cpu_units' => ['nullable', 'integer', 'between:1,10000'],
            'disk_mb_s' => ['nullable', 'integer', 'between:1,100000'],
            'disk_iops' => ['nullable', 'integer', 'between:10,10000000'],
        ];
    }

    public static function fromRequest(FormRequest $request): self
    {
        return new self(
            cpuLimit: $request->filled('cpu_limit') ? $request->float('cpu_limit') : null,
            cpuUnits: $request->filled('cpu_units') ? $request->integer('cpu_units') : null,
            diskMbS: $request->filled('disk_mb_s') ? $request->integer('disk_mb_s') : null,
            diskIops: $request->filled('disk_iops') ? $request->integer('disk_iops') : null,
        );
    }

    /**
     * @return array{cpu_limit: float|null, cpu_units: int|null, disk_mb_s: int|null, disk_iops: int|null}
     */
    public function columns(): array
    {
        return [
            'cpu_limit' => $this->cpuLimit,
            'cpu_units' => $this->cpuUnits,
            'disk_mb_s' => $this->diskMbS,
            'disk_iops' => $this->diskIops,
        ];
    }

    /**
     * The VM settings that apply the limits: `cpulimit` and `cpuunits` (or
     * their removal) and the boot disk with its throttling options replaced.
     * Only what differs from $config is returned, so a second run writes
     * nothing.
     *
     * @param  array<string, mixed>  $config  the VM's current configuration
     * @return array{params: array<string, string|int>, delete: list<string>}
     */
    public function proxmoxConfig(array $config): array
    {
        $params = [];
        $delete = [];

        foreach (['cpulimit' => $this->cpuLimit === null ? null : self::number($this->cpuLimit), 'cpuunits' => $this->cpuUnits] as $key => $value) {
            if ($value === null) {
                if (array_key_exists($key, $config)) {
                    $delete[] = $key;
                }
            } elseif ((string) ($config[$key] ?? '') !== (string) $value) {
                $params[$key] = $value;
            }
        }

        $disk = BootDisk::fromConfig($config);
        $current = $disk === null ? null : $config[$disk->key];

        if ($disk !== null && is_string($current)) {
            $value = $this->diskValue($current);

            if ($value !== $current) {
                $params[$disk->key] = $value;
            }
        }

        return ['params' => $params, 'delete' => $delete];
    }

    /**
     * "local-lvm:vm-100-disk-0,discard=on,size=40G" with the throttling options of these limits.
     */
    private function diskValue(string $current): string
    {
        $options = array_values(array_filter(
            explode(',', $current),
            fn (string $option) => ! in_array(explode('=', $option)[0], self::DISK_OPTIONS, true),
        ));

        if ($this->diskMbS !== null) {
            $options[] = "mbps_rd={$this->diskMbS}";
            $options[] = "mbps_wr={$this->diskMbS}";
        }

        if ($this->diskIops !== null) {
            $options[] = "iops_rd={$this->diskIops}";
            $options[] = "iops_wr={$this->diskIops}";
        }

        $value = implode(',', $options);

        // Unchanged options in another order are no change.
        return self::sameOptions($value, $current) ? $current : $value;
    }

    private static function sameOptions(string $a, string $b): bool
    {
        $split = function (string $value): array {
            $options = explode(',', $value);
            sort($options);

            return $options;
        };

        return $split($a) === $split($b);
    }

    /**
     * 1.5 → "1.5", 2.0 → "2".
     */
    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
