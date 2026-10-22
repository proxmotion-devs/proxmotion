<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Models\AppInstallation;
use App\Models\AppLog;
use App\Models\AppRun;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use App\Proxmox\Resources\GuestAgentResource;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Number;

/**
 * Keeps what app scripts print (docs/adr/0021). run.sh sends the output of
 * the script to run.log in the run's directory; the panel copies the part it
 * has not read yet through the guest agent: every minute from apps:poll, every
 * few seconds while an administrator watches the log, and once more when the
 * script ended. Secret inputs and outputs are masked before anything is
 * stored.
 */
final readonly class AppLogs
{
    /** Bytes asked for per command; their base64 stays well below the agent's output limit. */
    public const int CHUNK_BYTES = 49152;

    /** Chunks read at most in one go. */
    public const int MAX_CHUNKS = 8;

    /**
     * Reads a byte range of a file as base64. The file, offset and length are
     * positional arguments, never part of the script.
     */
    public const string READ_SCRIPT = 'dd if="$1" iflag=skip_bytes,count_bytes skip="$2" count="$3" status=none | base64 -w0';

    /** What a masked value is replaced with. */
    public const string MASK = '••••••••';

    /** Values shorter than this are not masked (they would hide ordinary text). */
    private const int MIN_SECRET_LENGTH = 4;

    public function __construct(private ProxmoxClientFactory $clients) {}

    /**
     * The log of an install attempt that has just started. Logs of earlier
     * attempts that were never finished are closed.
     */
    public function beginAttempt(AppInstallation $installation): AppLog
    {
        AppLog::query()
            ->where('app_installation_id', $installation->id)
            ->whereNull('app_run_id')
            ->whereNull('completed_at')
            ->update(['completed_at' => now()]);

        $log = new AppLog;
        $log->forceFill(['app_installation_id' => $installation->id, 'attempt' => $installation->attempts])->save();

        return $log;
    }

    /**
     * The log of an uninstall, upgrade or action that has just started.
     */
    public function beginRun(AppRun $run): AppLog
    {
        $log = new AppLog;
        $log->forceFill(['app_installation_id' => $run->app_installation_id, 'app_run_id' => $run->id])->save();

        return $log;
    }

    /**
     * The log of the installation's latest attempt.
     */
    public function current(AppInstallation $installation): ?AppLog
    {
        return $installation->logs()->where('attempt', $installation->attempts)->latest('id')->first();
    }

    /**
     * Copy what the running script printed since the last read. Complete
     * lines only: the rest may still be written. Skipped while another read
     * of the same log is under way; a guest that does not answer is tried
     * again next time.
     */
    public function follow(AppLog $log): void
    {
        Cache::lock($this->lockKey($log), 30)->get(fn () => $this->read($log, false, []));
    }

    /**
     * The script ended: copy the rest of the file, mask the outputs it
     * reported too, and close the log. A guest that no longer answers closes
     * it with what was read.
     *
     * @param  array<string, array{type: string, value: string, filename?: string|null}>  $outputs
     */
    public function finish(?AppLog $log, array $outputs = []): void
    {
        if ($log === null) {
            return;
        }

        try {
            Cache::lock($this->lockKey($log), 30)->block(15, fn () => $this->read($log, true, $outputs));
        } catch (LockTimeoutException) {
            $log->forceFill(['completed_at' => now()])->save();
        }
    }

    /**
     * Logs that are still open when the server's system went away.
     */
    public function closeAll(AppInstallation $installation): void
    {
        AppLog::query()->where('app_installation_id', $installation->id)->whereNull('completed_at')->update(['completed_at' => now()]);
    }

    /**
     * What a viewer who has read up to $cursor is missing: the new text, or
     * the whole log (reset) when it cannot be continued — the first request,
     * text left out in between, or a finished log (masked once more).
     *
     * @return array{text: string, reset: bool, cursor: int}
     */
    public function since(AppLog $log, ?int $cursor): array
    {
        $content = $log->content ?? '';
        $kept = $log->omitted_bytes > 0 ? strlen($content) - $log->head_bytes : strlen($content);
        $missing = $cursor === null ? -1 : $log->length - $cursor;

        if ($log->completed_at === null && $missing >= 0 && $missing <= $kept) {
            return ['text' => $missing === 0 ? '' : substr($content, -$missing), 'reset' => false, 'cursor' => $log->length];
        }

        return ['text' => self::render($log), 'reset' => true, 'cursor' => $log->length];
    }

    /**
     * The stored log with a note where its middle was left out.
     */
    public static function render(AppLog $log): string
    {
        $content = $log->content ?? '';

        if ($log->omitted_bytes === 0) {
            return $content;
        }

        $note = '[… '.__(':size left out', ['size' => Number::fileSize($log->omitted_bytes)]).' …]';

        return substr($content, 0, $log->head_bytes).$note."\n".substr($content, $log->head_bytes);
    }

    /**
     * Terminal output made readable: valid UTF-8, no colour or cursor codes,
     * and of a line redrawn with carriage returns (progress bars) only what it
     * ended as.
     */
    public static function clean(string $raw): string
    {
        $substitute = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        $text = mb_scrub($raw, 'UTF-8');
        mb_substitute_character($substitute);
        $text = preg_replace('/\e\[[0-?]*[ -\/]*[@-~]|\e\][^\a\e]*(?:\a|\e\\\\)|\e[@-Z\\\\-_]/', '', $text) ?? $text;
        $text = str_replace("\r\n", "\n", $text);

        $lines = array_map(function (string $line): string {
            $line = rtrim($line, "\r");
            $redrawn = strrpos($line, "\r");

            return $redrawn === false ? $line : substr($line, $redrawn + 1);
        }, explode("\n", $text));

        return preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', implode("\n", $lines)) ?? '';
    }

    /**
     * Replace each of the values in the text with MASK.
     *
     * @param  list<string>  $secrets
     */
    public static function mask(string $text, array $secrets): string
    {
        $secrets = array_values(array_unique(array_filter($secrets, fn (string $secret) => mb_strlen($secret) >= self::MIN_SECRET_LENGTH)));
        // Longer first, so a secret containing another is masked whole.
        usort($secrets, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return $secrets === [] ? $text : str_replace($secrets, self::MASK, $text);
    }

    /**
     * @param  array<string, array{type: string, value: string, filename?: string|null}>  $outputs
     */
    private function read(AppLog $log, bool $final, array $outputs): void
    {
        $log->refresh();

        if ($log->completed_at !== null) {
            return;
        }

        $raw = '';

        try {
            $installation = $log->installation;
            $server = $installation->server;
            $agent = $this->clients->forCluster($server->cluster)->guestAgent($server->node->name);

            for ($i = 0; $i < self::MAX_CHUNKS; $i++) {
                $command = AppGuest::readCommand($installation->platform(), $installation->guestDirectory(), $log->guestFile(), $log->offset + strlen($raw), self::CHUNK_BYTES);
                $chunk = $this->chunk($agent, $server->vmid, $command);
                $raw .= $chunk;

                if (strlen($chunk) < self::CHUNK_BYTES) {
                    break;
                }
            }
        } catch (ProxmoxException) {
            if (! $final) {
                return;
            }
        }

        if (! $final) {
            $end = strrpos($raw, "\n");

            // The last line may still be written; one longer than a whole read is taken as it is.
            if ($end !== false) {
                $raw = substr($raw, 0, $end + 1);
            } elseif (strlen($raw) < self::CHUNK_BYTES) {
                $raw = '';
            }
        }

        $secrets = $this->secrets($log, $outputs);
        $text = self::mask(self::clean($raw), $secrets);
        $content = $this->limit($log, ($log->content ?? '').$text);

        if ($final) {
            // Outputs the script printed before reporting them are only known now.
            $content = self::mask($content, $secrets);
        }

        $log->forceFill([
            'content' => $content,
            'offset' => $log->offset + strlen($raw),
            'length' => $log->length + strlen($text),
            'read_at' => now(),
            'completed_at' => $final ? now() : null,
        ])->save();
    }

    /**
     * @param  non-empty-list<string>  $command  AppGuest::readCommand()
     *
     * @throws ProxmoxException
     */
    private function chunk(GuestAgentResource $agent, int $vmid, array $command): string
    {
        $result = $agent->run($vmid, $command, timeoutSeconds: 15);

        // A file that is not there yet reads as empty.
        if (! $result->succeeded()) {
            return '';
        }

        return (string) base64_decode(trim($result->stdout), true);
    }

    /**
     * At most MAX_BYTES: the start (up to HEAD_BYTES, whole lines) and the
     * newest output stay, the middle is left out.
     */
    private function limit(AppLog $log, string $content): string
    {
        if (strlen($content) <= AppLog::MAX_BYTES) {
            return $content;
        }

        if ($log->omitted_bytes === 0) {
            $head = strrpos(substr($content, 0, AppLog::HEAD_BYTES), "\n");
            $log->head_bytes = $head === false ? AppLog::HEAD_BYTES : $head + 1;
        }

        $tail = $log->head_bytes + strlen($content) - AppLog::MAX_BYTES;
        $line = strpos($content, "\n", $tail);

        if ($line !== false && $line - $tail < 4096) {
            $tail = $line + 1;
        }

        // Never in the middle of a character.
        while ($tail < strlen($content) && (ord($content[$tail]) & 0xC0) === 0x80) {
            $tail++;
        }

        $log->omitted_bytes += $tail - $log->head_bytes;

        return substr($content, 0, $log->head_bytes).substr($content, $tail);
    }

    /**
     * Values that must not be kept in a log: secret inputs of the
     * installation and the run, and secret text outputs.
     *
     * @param  array<string, array{type: string, value: string, filename?: string|null}>  $outputs
     * @return list<string>
     */
    private function secrets(AppLog $log, array $outputs): array
    {
        $installation = $log->installation;
        $recipe = $installation->version->recipe();
        $secrets = self::secretInputs($recipe->inputs, $installation->inputs ?? []);
        $declared = $recipe->outputs;
        $outputs += $installation->outputs ?? [];

        if ($log->run !== null) {
            $secrets = [...$secrets, ...self::secretInputs($log->run->declaredInputs(), $log->run->inputs ?? [])];
            $declared = [...$declared, ...$log->run->declaredOutputs()];
            $outputs += $log->run->outputs ?? [];
        }

        foreach ($declared as $output) {
            $value = $outputs[$output->key] ?? null;

            if ($output->secret && ! $output->isFile() && $value !== null && $value['type'] !== 'file') {
                $secrets[] = $value['value'];
            }
        }

        return $secrets;
    }

    /**
     * @param  list<RecipeInput>  $declared
     * @param  array<string, string|int|bool>  $values
     * @return list<string>
     */
    private static function secretInputs(array $declared, array $values): array
    {
        $secrets = [];

        foreach ($declared as $input) {
            if ($input->isSecret() && is_string($values[$input->key] ?? null)) {
                $secrets[] = $values[$input->key];
            }
        }

        return $secrets;
    }

    private function lockKey(AppLog $log): string
    {
        return 'app-log:'.$log->id;
    }
}
