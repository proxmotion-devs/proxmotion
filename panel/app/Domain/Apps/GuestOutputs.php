<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\Resources\GuestAgentResource;
use Illuminate\Support\Str;

/**
 * Reads the declared outputs a script left in its directory (docs/adr/0015):
 * texts and URLs from outputs.json, files from outputs/<filename>.
 */
final class GuestOutputs
{
    /** The largest output file stored. */
    public const MAX_FILE_BYTES = 1048576;

    /**
     * @param  list<RecipeOutput>  $declared
     * @return array<string, array{type: string, value: string, filename?: string|null}>
     *
     * @throws ProxmoxException
     */
    public static function read(GuestAgentResource $agent, int $vmid, string $directory, array $declared): array
    {
        $texts = [];
        $outputs = [];

        if (array_filter($declared, fn (RecipeOutput $output) => ! $output->isFile()) !== []) {
            try {
                $decoded = json_decode($agent->readFile($vmid, $directory.'/outputs.json')->content, true);
                $texts = is_array($decoded) ? $decoded : [];
            } catch (ProxmoxException) {
                $texts = [];
            }
        }

        foreach ($declared as $output) {
            if ($output->isFile()) {
                try {
                    $file = $agent->readFile($vmid, $directory.'/outputs/'.$output->filename);
                } catch (ProxmoxException) {
                    continue;
                }

                if (! $file->truncated && strlen($file->content) <= self::MAX_FILE_BYTES) {
                    $outputs[$output->key] = ['type' => 'file', 'value' => base64_encode($file->content), 'filename' => $output->filename];
                }

                continue;
            }

            $value = $texts[$output->key] ?? null;

            if (is_string($value) || is_int($value)) {
                $outputs[$output->key] = ['type' => $output->type, 'value' => Str::limit((string) $value, 2000)];
            }
        }

        return $outputs;
    }

    /**
     * status.json of a script's directory: its state, step and message.
     *
     * @return array{state: string|null, step: string|null, message: string|null, progress: int|null}
     *
     * @throws ProxmoxException
     */
    public static function status(GuestAgentResource $agent, int $vmid, string $directory): array
    {
        $status = json_decode($agent->readFile($vmid, $directory.'/status.json')->content, true);
        $status = is_array($status) ? $status : [];

        return [
            'state' => is_string($status['status'] ?? null) ? $status['status'] : null,
            'step' => is_string($status['step'] ?? null) && $status['step'] !== '' ? Str::limit($status['step'], 197) : null,
            'message' => is_string($status['message'] ?? null) && $status['message'] !== '' ? $status['message'] : null,
            'progress' => is_int($status['progress'] ?? null) ? max(0, min(99, $status['progress'])) : null,
        ];
    }
}
