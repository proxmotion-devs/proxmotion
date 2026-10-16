<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiScope;
use App\Models\Concerns\HasPublicUuid;
use App\Support\Network\Cidr;
use Database\Factories\ApiTokenFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A credential of the billing API (docs/adr/0008). Only a SHA-256 hash of
 * the secret is stored; the secret is shown once when the token is created.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property int $billing_system_id the billing system whose servers the token manages
 * @property string $token_hash
 * @property string $token_prefix
 * @property list<string> $scopes
 * @property list<string>|null $allowed_ips
 * @property int|null $created_by
 * @property Carbon|null $last_used_at
 * @property string|null $last_used_ip
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 * @property-read BillingSystem $billingSystem
 */
class ApiToken extends Model
{
    /** @use HasFactory<ApiTokenFactory> */
    use HasFactory, HasPublicUuid;

    public const PREFIX = 'pmx_';

    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'allowed_ips' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Create a token and return it with its secret, which is not stored.
     *
     * @param  list<ApiScope>  $scopes
     * @param  list<string>|null  $allowedIps
     * @return array{ApiToken, string}
     */
    public static function issue(BillingSystem $billingSystem, string $name, array $scopes, ?array $allowedIps = null, ?DateTimeInterface $expiresAt = null, ?User $creator = null): array
    {
        $secret = self::PREFIX.Str::random(48);

        $token = new self;
        $token->forceFill([
            'billing_system_id' => $billingSystem->id,
            'name' => $name,
            'token_hash' => self::hash($secret),
            'token_prefix' => substr($secret, 0, 12),
            'scopes' => array_values(array_unique(array_map(fn (ApiScope $scope) => $scope->value, $scopes))),
            'allowed_ips' => $allowedIps === [] ? null : $allowedIps,
            'expires_at' => $expiresAt,
            'created_by' => $creator?->id,
        ])->save();

        return [$token, $secret];
    }

    /**
     * The usable token for a secret from an Authorization header, if any.
     */
    public static function findActive(string $secret): ?self
    {
        if (! str_starts_with($secret, self::PREFIX)) {
            return null;
        }

        $token = self::query()->where('token_hash', self::hash($secret))->first();

        return $token !== null && $token->isActive() ? $token : null;
    }

    /**
     * The token with this id if it is still active and has the scope, e.g.
     * for a console session or link it issued earlier.
     */
    public static function usableFor(mixed $id, ApiScope $scope): ?self
    {
        $token = is_int($id) ? self::query()->find($id) : null;

        return $token !== null && $token->isActive() && $token->hasScope($scope) ? $token : null;
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function hasScope(ApiScope $scope): bool
    {
        return in_array($scope->value, $this->scopes, true);
    }

    /**
     * Whether a request from this address may use the token.
     */
    public function allowsIp(?string $ip): bool
    {
        if ($this->allowed_ips === null || $this->allowed_ips === []) {
            return true;
        }

        if ($ip === null) {
            return false;
        }

        foreach ($this->allowed_ips as $allowed) {
            $cidr = str_contains($allowed, '/') ? $allowed : $allowed.(str_contains($allowed, ':') ? '/128' : '/32');

            if (Cidr::isValid($cidr) && Cidr::parse($cidr)->contains($ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return BelongsTo<BillingSystem, $this>
     */
    public function billingSystem(): BelongsTo
    {
        return $this->belongsTo(BillingSystem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
