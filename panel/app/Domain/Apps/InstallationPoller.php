<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Enums\AppInstallationStatus;
use App\Models\AppInstallation;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use App\Proxmox\Resources\GuestAgentResource;
use Illuminate\Support\Str;

/**
 * Follows a running installer (docs/adr/0015) by reading its status.json
 * through the guest agent. When it reports "installed", the declared outputs
 * are read and stored encrypted; past the recipe's timeout the installation
 * fails, also when the agent stopped answering.
 */
final readonly class InstallationPoller
{
    /** The largest output file stored. */
    public const MAX_OUTPUT_FILE_BYTES = 1048576;

    public function __construct(
        private ProxmoxClientFactory $clients,
        private AppInstallations $installations,
    ) {}

    public function poll(AppInstallation $installation): void
    {
        if ($installation->status !== AppInstallationStatus::Installing) {
            return;
        }

        $server = $installation->server;
        $agent = $this->clients->forCluster($server->cluster)->guestAgent($server->node->name);
        $timedOut = $installation->deadline()?->isPast() ?? false;

        try {
            $status = json_decode($agent->readFile($server->vmid, $installation->guestDirectory().'/status.json')->content, true);
        } catch (ProxmoxException) {
            // Stopped, rebooting or the agent is down: try again next time, unless it is too late.
            $timedOut && $this->timedOut($installation);

            return;
        }

        $state = is_array($status) && is_string($status['status'] ?? null) ? $status['status'] : null;
        $step = is_array($status) && is_string($status['step'] ?? null) && $status['step'] !== '' ? Str::limit($status['step'], 197) : null;

        if ($state === 'failed') {
            $message = is_string($status['message'] ?? null) && $status['message'] !== '' ? $status['message'] : 'The installer failed.';
            $this->installations->failed($installation, $message, $step);

            return;
        }

        if ($state === 'installed') {
            try {
                $outputs = $this->outputs($agent, $server->vmid, $installation);
            } catch (ProxmoxException) {
                $timedOut && $this->timedOut($installation);

                return;
            }

            $this->installations->installed($installation, $outputs, $step);

            return;
        }

        if ($timedOut) {
            $this->timedOut($installation);

            return;
        }

        $progress = is_array($status) && is_int($status['progress'] ?? null) ? max(0, min(99, $status['progress'])) : $installation->progress;
        $installation->forceFill(['progress' => $progress, 'step' => $step ?? $installation->step])->save();
    }

    /**
     * The declared outputs the installer left: texts from outputs.json, files from outputs/.
     *
     * @return array<string, array{type: string, value: string, filename?: string|null}>
     *
     * @throws ProxmoxException
     */
    private function outputs(GuestAgentResource $agent, int $vmid, AppInstallation $installation): array
    {
        $recipe = $installation->recipe->recipe();
        $directory = $installation->guestDirectory();
        $texts = [];
        $outputs = [];

        if (array_filter($recipe->outputs, fn (RecipeOutput $output) => ! $output->isFile()) !== []) {
            try {
                $decoded = json_decode($agent->readFile($vmid, $directory.'/outputs.json')->content, true);
                $texts = is_array($decoded) ? $decoded : [];
            } catch (ProxmoxException) {
                $texts = [];
            }
        }

        foreach ($recipe->outputs as $output) {
            if ($output->isFile()) {
                try {
                    $file = $agent->readFile($vmid, $directory.'/outputs/'.$output->filename);
                } catch (ProxmoxException) {
                    continue;
                }

                if (! $file->truncated && strlen($file->content) <= self::MAX_OUTPUT_FILE_BYTES) {
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

    private function timedOut(AppInstallation $installation): void
    {
        $minutes = $installation->recipe->recipe()->timeoutMinutes;

        $this->installations->failed($installation, __('The installation did not finish within :minutes minutes.', ['minutes' => (string) $minutes]));
    }
}
