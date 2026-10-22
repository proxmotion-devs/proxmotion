<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Enums\OsPlatform;
use App\Support\Network\Cidr;
use Closure;

/**
 * One inbound rule of a server's firewall or of a rule set (docs/adr/0035):
 * accept or drop traffic of a protocol, to a port or range, from a source.
 * Written to the VM firewall as one Proxmox rule, two for ICMP from anywhere
 * and for "any" with a port (TCP and UDP).
 */
final readonly class FirewallRule
{
    public const ACTIONS = ['accept', 'drop'];

    public const PROTOCOLS = ['tcp', 'udp', 'icmp', 'any'];

    /** Protocols a rule may name a port for: "any" with a port is TCP and UDP to it. */
    public const PORT_PROTOCOLS = ['tcp', 'udp', 'any'];

    /** Rules per server or rule set (the panel's own rules do not count). */
    public const MAX_RULES = 50;

    /** Comments of the rules the panel writes for these, after "Proxmotion rule". */
    public const COMMENT_PREFIX = 'Proxmotion rule';

    public function __construct(
        public string $action,
        public string $protocol,
        /** A port or range ("22", "1000-2000"), not for icmp; null: every port. */
        public ?string $port = null,
        /** An address or CIDR; null: anywhere. */
        public ?string $source = null,
        public ?string $comment = null,
        /** The SSH or RDP rule added when the firewall was turned on without rules. */
        public bool $isDefault = false,
    ) {}

    /**
     * Validation of a list of rules under $key (e.g. "rules"), the same in the
     * admin forms, rule sets and the API.
     *
     * @return array<string, list<mixed>>
     */
    public static function validation(string $key): array
    {
        return [
            $key => ['present', 'array', 'max:'.self::MAX_RULES],
            "{$key}.*" => ['array'],
            "{$key}.*.action" => ['required', 'in:'.implode(',', self::ACTIONS)],
            "{$key}.*.protocol" => ['required', 'in:'.implode(',', self::PROTOCOLS)],
            "{$key}.*.port" => ['nullable', 'string', 'max:11', self::portRule()],
            "{$key}.*.source" => ['nullable', 'string', 'max:64', self::sourceRule()],
            // Any text but control characters: it becomes part of a Proxmox comment.
            "{$key}.*.comment" => ['nullable', 'string', 'max:60', 'not_regex:/[\x00-\x1F\x7F]/'],
        ];
    }

    /**
     * Problems of one rule that the per-field validation cannot see, as English translation keys.
     *
     * @param  array<string, mixed>  $rule
     */
    public static function problem(array $rule): ?string
    {
        $protocol = $rule['protocol'] ?? null;
        $port = $rule['port'] ?? null;

        if (is_string($port) && $port !== '' && ! in_array($protocol, self::PORT_PROTOCOLS, true)) {
            return 'ICMP rules cannot have a port.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data  as validated
     */
    public static function fromArray(array $data, bool $isDefault = false): self
    {
        $text = fn (string $key): ?string => is_string($data[$key] ?? null) && trim($data[$key]) !== '' ? trim($data[$key]) : null;
        $source = $text('source');

        return new self(
            action: (string) $data['action'],
            protocol: (string) $data['protocol'],
            port: in_array($data['protocol'], self::PORT_PROTOCOLS, true) ? $text('port') : null,
            source: $source === null ? null : Cidr::canonical($source),
            comment: $text('comment'),
            isDefault: $isDefault,
        );
    }

    /**
     * The rule a server gets when its firewall is turned on without rules:
     * SSH on Linux, RDP on Windows, both when the platform is not known.
     *
     * @return list<self>
     */
    public static function defaultsFor(?OsPlatform $platform): array
    {
        $ssh = new self('accept', 'tcp', '22', comment: 'SSH', isDefault: true);
        $rdp = new self('accept', 'tcp', '3389', comment: 'RDP', isDefault: true);

        return match ($platform) {
            OsPlatform::Linux => [$ssh],
            OsPlatform::Windows => [$rdp],
            null => [$ssh, $rdp],
        };
    }

    /**
     * Whether the rule lets in TCP traffic to $port from anywhere or from some source.
     */
    public function opens(int $port): bool
    {
        if ($this->action !== 'accept' || ! in_array($this->protocol, ['tcp', 'any'], true)) {
            return false;
        }

        if ($this->port === null) {
            return true;
        }

        $bounds = explode('-', $this->port);

        return $port >= (int) $bounds[0] && $port <= (int) ($bounds[1] ?? $bounds[0]);
    }

    /**
     * @return array{action: string, protocol: string, port: string|null, source: string|null, comment: string|null}
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'protocol' => $this->protocol,
            'port' => $this->port,
            'source' => $this->source,
            'comment' => $this->comment,
        ];
    }

    /**
     * The Proxmox rules (POST .../firewall/rules) of this rule: ICMP is
     * "icmp" for IPv4 and "ipv6-icmp" for IPv6, so ICMP from anywhere
     * becomes two rules. Proxmox takes a port only with a protocol, so "any"
     * with a port becomes a TCP and a UDP rule; without one it names no
     * protocol and matches all traffic.
     *
     * @return list<array<string, string|int>>
     */
    public function proxmoxRules(): array
    {
        $base = array_filter([
            'type' => 'in',
            'action' => strtoupper($this->action),
            'proto' => in_array($this->protocol, ['tcp', 'udp'], true) ? $this->protocol : null,
            'dport' => $this->port === null ? null : str_replace('-', ':', $this->port),
            'source' => $this->source,
            'enable' => 1,
            'comment' => self::COMMENT_PREFIX.($this->comment === null ? '' : ": {$this->comment}"),
        ], fn ($value) => $value !== null);

        if ($this->protocol === 'any' && $this->port !== null) {
            return [[...$base, 'proto' => 'tcp'], [...$base, 'proto' => 'udp']];
        }

        if ($this->protocol !== 'icmp') {
            return [$base];
        }

        $version = $this->source === null ? null : (str_contains($this->source, ':') ? 6 : 4);

        return array_values(array_filter([
            $version !== 6 ? [...$base, 'proto' => 'icmp'] : null,
            $version !== 4 ? [...$base, 'proto' => 'ipv6-icmp'] : null,
        ]));
    }

    private static function portRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || preg_match('/^(\d{1,5})(?:-(\d{1,5}))?$/', $value, $match) !== 1) {
                $fail(__('Enter a port or a range such as 1000-2000.'));

                return;
            }

            $from = (int) $match[1];
            $to = isset($match[2]) ? (int) $match[2] : $from;

            if ($from < 1 || $to > 65535 || $from > $to) {
                $fail(__('Enter a port or a range such as 1000-2000.'));
            }
        };
    }

    private static function sourceRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! Cidr::isAddressOrNetwork($value)) {
                $fail(__('Enter an address or a network in CIDR notation.'));
            }
        };
    }
}
