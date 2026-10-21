<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Firewall\FirewallRule;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Firewall rules administrators prepare: customers apply them to their
 * servers, and a package's new servers start with them (docs/adr/0035 §3).
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $description
 * @property list<array{action: string, protocol: string, port: string|null, source: string|null, comment: string|null}> $rules
 * @property bool $is_active offered to customers and on the forms
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description', 'rules', 'is_active'])]
class FirewallRuleset extends Model
{
    use HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return list<FirewallRule>
     */
    public function firewallRules(): array
    {
        return array_map(fn (array $rule) => FirewallRule::fromArray($rule), $this->rules);
    }

    /**
     * Whether a server with these rules can still be reached through SSH or RDP.
     */
    public function opensRemoteAccess(): bool
    {
        foreach ($this->firewallRules() as $rule) {
            if ($rule->opens(22) || $rule->opens(3389)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return HasMany<Package, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }
}
