<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

/**
 * GET /nodes/{node}/qemu/{vmid}/agent/exec-status. Proxmox has already
 * decoded stdout and stderr.
 */
final readonly class GuestExecStatus
{
    public function __construct(
        public bool $exited,
        public ?int $exitCode,
        public ?int $signal,
        public string $stdout,
        public string $stderr,
        public bool $stdoutTruncated,
        public bool $stderrTruncated,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromApi(array $data): self
    {
        $int = fn (string $key) => is_numeric($data[$key] ?? null) ? (int) $data[$key] : null;
        $string = fn (string $key) => is_string($data[$key] ?? null) ? $data[$key] : '';

        return new self(
            exited: (bool) ($data['exited'] ?? false),
            exitCode: $int('exitcode'),
            signal: $int('signal'),
            stdout: $string('out-data'),
            stderr: $string('err-data'),
            stdoutTruncated: (bool) ($data['out-truncated'] ?? false),
            stderrTruncated: (bool) ($data['err-truncated'] ?? false),
        );
    }

    /**
     * Exited normally with exit code 0.
     */
    public function succeeded(): bool
    {
        return $this->exited && $this->exitCode === 0 && $this->signal === null;
    }
}
