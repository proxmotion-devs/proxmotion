<?php

declare(strict_types=1);

namespace App\Support\Ssh;

use InvalidArgumentException;

/**
 * One OpenSSH public key line ("type base64-blob comment"). Parsing checks the
 * blob itself, not just its shape: the key type inside the blob must match,
 * RSA keys need at least 2048 bits and DSA keys are refused (OpenSSH has
 * disabled them). A parsed key is safe to pass to cloud-init.
 */
final readonly class PublicKey
{
    private const TYPES = [
        'ssh-ed25519',
        'ssh-rsa',
        'ecdsa-sha2-nistp256',
        'ecdsa-sha2-nistp384',
        'ecdsa-sha2-nistp521',
        'sk-ssh-ed25519@openssh.com',
        'sk-ecdsa-sha2-nistp256@openssh.com',
    ];

    private const MIN_RSA_BITS = 2048;

    private function __construct(
        public string $type,
        private string $blob,
        public string $comment,
    ) {}

    /**
     * @throws InvalidArgumentException with an English translation key
     */
    public static function parse(string $line): self
    {
        $line = trim($line);

        // Tabs may separate the fields; any other control character is refused.
        if ($line === '' || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $line) === 1) {
            throw new InvalidArgumentException('Each line must be a public SSH key, e.g. "ssh-ed25519 AAAA… user@host".');
        }

        $parts = preg_split('/\s+/', $line, 3) ?: [];
        [$type, $encoded, $comment] = array_pad($parts, 3, '');

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unsupported SSH key type. Use ed25519, ECDSA or RSA (2048 bits or more).');
        }

        $blob = base64_decode($encoded, true);

        if ($blob === false || self::readString($blob, 0)[0] !== $type) {
            throw new InvalidArgumentException('Each line must be a public SSH key, e.g. "ssh-ed25519 AAAA… user@host".');
        }

        if ($type === 'ssh-rsa' && self::rsaBits($blob) < self::MIN_RSA_BITS) {
            throw new InvalidArgumentException('Unsupported SSH key type. Use ed25519, ECDSA or RSA (2048 bits or more).');
        }

        return new self($type, $blob, mb_substr(trim($comment), 0, 255));
    }

    /**
     * Parse one key per line, skipping empty lines.
     *
     * @return list<self>
     *
     * @throws InvalidArgumentException
     */
    public static function parseMany(string $text): array
    {
        $keys = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (trim($line) !== '') {
                $keys[] = self::parse($line);
            }
        }

        return $keys;
    }

    /**
     * The OpenSSH fingerprint, e.g. "SHA256:uNiVztksCsDhcc0u9e8BujQXVUpKZIDTMczCvj3tD2s".
     */
    public function fingerprint(): string
    {
        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $this->blob, true)), '=');
    }

    /**
     * The normalized line: type, blob and (if any) comment, separated by single spaces.
     */
    public function toString(): string
    {
        return rtrim("{$this->type} ".base64_encode($this->blob)." {$this->comment}");
    }

    /**
     * Read one SSH wire-format string (uint32 length + bytes).
     *
     * @return array{string, int} the string and the offset after it
     */
    private static function readString(string $blob, int $offset): array
    {
        if (strlen($blob) < $offset + 4) {
            return ['', $offset];
        }

        $length = unpack('N', substr($blob, $offset, 4))[1] ?? 0;

        if (! is_int($length) || strlen($blob) < $offset + 4 + $length) {
            return ['', $offset];
        }

        return [substr($blob, $offset + 4, $length), $offset + 4 + $length];
    }

    /**
     * Modulus size of an ssh-rsa blob: string type, mpint e, mpint n.
     */
    private static function rsaBits(string $blob): int
    {
        [, $offset] = self::readString($blob, 0);
        [, $offset] = self::readString($blob, $offset);
        [$modulus] = self::readString($blob, $offset);
        $modulus = ltrim($modulus, "\0");

        return $modulus === '' ? 0 : (strlen($modulus) - 1) * 8 + strlen(decbin(ord($modulus[0])));
    }
}
