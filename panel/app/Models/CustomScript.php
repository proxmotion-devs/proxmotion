<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GuestScriptEvent;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An administrator's own script on an OS template (docs/adr/0043): run by
 * hand from the server page (manual) and/or after the fixed script of some
 * events (docs/adr/0010), in position order. Only content runs outside
 * trials; the draft runs only in trials until it is published. Written only
 * by the admin scripts page, which audits it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $os_template_id
 * @property string $name
 * @property int $position
 * @property bool $manual
 * @property list<string> $events GuestScriptEvent values
 * @property string|null $content null until the first draft is published
 * @property string|null $draft_content
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OsTemplate $osTemplate
 */
class CustomScript extends Model
{
    use HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'manual' => 'boolean',
            'events' => 'array',
        ];
    }

    public function runsAt(GuestScriptEvent $event): bool
    {
        return in_array($event->value, $this->events, true);
    }

    /** The task step of its runs at events. */
    public function step(): string
    {
        return self::stepFor($this->name);
    }

    public static function stepFor(string $name): string
    {
        return 'script:'.$name;
    }

    /**
     * @return BelongsTo<OsTemplate, $this>
     */
    public function osTemplate(): BelongsTo
    {
        return $this->belongsTo(OsTemplate::class);
    }
}
