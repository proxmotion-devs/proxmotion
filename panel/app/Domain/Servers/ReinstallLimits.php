<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Server;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How often a server's billing system may reinstall it (docs/adr/0045): its
 * package's limit, or its own, within a rolling window of days. Only
 * reinstalls through the billing API count, and failed ones do not;
 * administrators are never limited.
 */
final class ReinstallLimits
{
    /** The longest window, in days. */
    public const MAX_PERIOD_DAYS = 365;

    /**
     * Validation of a limit as packages and servers take it: an empty count
     * is unlimited, 0 allows none, and a count needs its window.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'reinstall_limit' => ['nullable', 'integer', 'between:0,1000'],
            'reinstall_period_days' => ['nullable', 'required_with:reinstall_limit', 'integer', 'between:1,'.self::MAX_PERIOD_DAYS],
        ];
    }

    /**
     * The limit that applies: the server's own when it has one, otherwise
     * its package's. Null when unlimited.
     *
     * @return array{limit: int, period_days: int}|null
     */
    public function limitOf(Server $server): ?array
    {
        [$limit, $days] = $server->reinstall_limit_custom
            ? [$server->reinstall_limit, $server->reinstall_period_days]
            : [$server->package?->reinstall_limit, $server->package?->reinstall_period_days];

        return $limit === null || $days === null ? null : ['limit' => $limit, 'period_days' => $days];
    }

    /**
     * Where the server stands: used and remaining reinstalls of the window,
     * and when the next one is possible once none remain (null for a limit
     * of 0). All null but source when unlimited.
     *
     * @return array{source: 'server'|'package', limit: int|null, period_days: int|null, used: int|null, remaining: int|null, available_at: string|null}
     */
    public function usage(Server $server): array
    {
        $source = $server->reinstall_limit_custom ? 'server' : 'package';
        $limit = $this->limitOf($server);

        if ($limit === null) {
            return ['source' => $source, 'limit' => null, 'period_days' => null, 'used' => null, 'remaining' => null, 'available_at' => null];
        }

        $counted = $this->counted($server, $limit['period_days']);
        $used = $counted->count();

        return [
            'source' => $source,
            'limit' => $limit['limit'],
            'period_days' => $limit['period_days'],
            'used' => $used,
            'remaining' => max(0, $limit['limit'] - $used),
            'available_at' => $this->availableAt($counted, $limit['limit'], $limit['period_days'])?->toIso8601String(),
        ];
    }

    /**
     * @throws ReinstallLimitReached when the billing system has used up the server's reinstalls
     */
    public function check(Server $server): void
    {
        $limit = $this->limitOf($server);

        if ($limit === null) {
            return;
        }

        $counted = $this->counted($server, $limit['period_days']);

        if ($counted->count() >= $limit['limit']) {
            throw new ReinstallLimitReached($limit['limit'], $limit['period_days'], $this->availableAt($counted, $limit['limit'], $limit['period_days']));
        }
    }

    /**
     * When the reinstalls of the server's billing system in the window
     * started, oldest first; only those after a reset count.
     *
     * @return Collection<int, CarbonImmutable>
     */
    private function counted(Server $server, int $days): Collection
    {
        $since = now()->subDays($days);

        if ($server->reinstalls_reset_at !== null && $server->reinstalls_reset_at->greaterThan($since)) {
            $since = $server->reinstalls_reset_at;
        }

        return Task::query()
            ->where('server_id', $server->id)
            ->where('type', TaskType::Reinstall)
            ->whereNotNull('api_token_id')
            ->where('status', '!=', TaskStatus::Failed)
            ->where('created_at', '>', $since)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['created_at'])
            ->map(fn (Task $task): CarbonImmutable => CarbonImmutable::instance($task->created_at ?? now()))
            ->values();
    }

    /**
     * When enough counted reinstalls have left the window for one more;
     * null while one remains or for a limit of 0.
     *
     * @param  Collection<int, CarbonImmutable>  $counted
     */
    private function availableAt(Collection $counted, int $limit, int $days): ?CarbonImmutable
    {
        if ($limit === 0 || $counted->count() < $limit) {
            return null;
        }

        return $counted->get($counted->count() - $limit)?->addDays($days);
    }
}
