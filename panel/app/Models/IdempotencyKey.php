<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The answer to a write request sent with an Idempotency-Key, replayed when
 * the same token sends the same key again (docs/adr/0008). Kept for a day.
 *
 * @property int $id
 * @property int $api_token_id
 * @property string $key
 * @property string $request_hash
 * @property int|null $status_code
 * @property string|null $response
 * @property Carbon|null $created_at
 */
class IdempotencyKey extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public const KEEP_HOURS = 24;

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<', now()->subHours(self::KEEP_HOURS));
    }
}
