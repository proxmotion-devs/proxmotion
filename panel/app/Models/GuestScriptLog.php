<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GuestScriptEvent;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One run of a guest script inside a server (docs/adr/0023): during an
 * operation or an administrator's trial, with the script, its inputs, exit
 * code and output. The output of password scripts is never kept (they may
 * print the password they got). Shown to administrators only, pruned after
 * KEEP_DAYS. Only GuestScripts writes logs, so nothing is fillable.
 *
 * @property int $id
 * @property string $uuid
 * @property int $server_id
 * @property int|null $os_template_id
 * @property int|null $task_id
 * @property GuestScriptEvent $event
 * @property string $source default, published or draft
 * @property bool $trace
 * @property string $status running, succeeded or failed
 * @property int|null $exit_code
 * @property string|null $message
 * @property string $script
 * @property array<string, mixed> $inputs
 * @property string|null $stdout
 * @property string|null $stderr
 * @property bool $output_truncated
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 * @property-read Task|null $task
 */
class GuestScriptLog extends Model
{
    use HasPublicUuid, MassPrunable;

    public const int KEEP_DAYS = 90;

    /** The most kept of stdout and of stderr. */
    public const int MAX_OUTPUT_BYTES = 65536;

    public const string SOURCE_DEFAULT = 'default';

    public const string SOURCE_PUBLISHED = 'published';

    public const string SOURCE_DRAFT = 'draft';

    protected $hidden = ['stdout', 'stderr'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => GuestScriptEvent::class,
            'trace' => 'boolean',
            'inputs' => 'array',
            'stdout' => 'encrypted',
            'stderr' => 'encrypted',
            'output_truncated' => 'boolean',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
