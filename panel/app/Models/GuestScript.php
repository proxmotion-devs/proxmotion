<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GuestScriptEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An OS template's own script for one event, replacing the default
 * (docs/adr/0010), and its draft (docs/adr/0023): only content runs during
 * operations; the draft runs only in trials until it is published. Written
 * only by the admin scripts page, which audits it.
 *
 * @property int $id
 * @property int $os_template_id
 * @property GuestScriptEvent $event
 * @property string|null $content null while only a draft exists (the default runs)
 * @property string|null $draft_content
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OsTemplate $osTemplate
 */
class GuestScript extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => GuestScriptEvent::class,
        ];
    }

    /**
     * @return BelongsTo<OsTemplate, $this>
     */
    public function osTemplate(): BelongsTo
    {
        return $this->belongsTo(OsTemplate::class);
    }
}
