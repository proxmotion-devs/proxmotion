<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Firewall\FirewallRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One inbound rule of a server's firewall (docs/adr/0035 §1). Written only by
 * ServerFirewalls, never mass-assigned.
 *
 * @property int $id
 * @property int $server_id
 * @property int $position
 * @property string $action accept or drop
 * @property string $protocol tcp, udp, icmp or any
 * @property string|null $port e.g. "22" or "1000-2000"
 * @property string|null $source an address or CIDR; null: anywhere
 * @property string|null $comment
 * @property bool $is_default the SSH or RDP rule added when the firewall was turned on without rules
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 */
class ServerFirewallRule extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    public function toRule(): FirewallRule
    {
        return new FirewallRule($this->action, $this->protocol, $this->port, $this->source, $this->comment, $this->is_default);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
