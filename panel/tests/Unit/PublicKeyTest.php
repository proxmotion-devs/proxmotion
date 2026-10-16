<?php

namespace Tests\Unit;

use App\Support\Ssh\PublicKey;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PublicKeyTest extends TestCase
{
    // Throwaway keys made with ssh-keygen; fingerprints from `ssh-keygen -lf`.
    public const ED25519 = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHq0RLG5bN7TQBqYB93lEHfqk4K88b8lHfDZyvpD/FnZ test@example.com';

    public const RSA = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQChpY2A8aYGnI+vOwUE2QjSFgYq9QUB8lthFZGKUhdsm17EB54hOaXR3JiJvCFuLPxp03jmny7h8KBYmaaLxDVSsjljcg01xQ5H2AdqIIImwFv9kGwAkJfQAuiU/wYN9D+sZ7vegFjAwJ+Ez9F+s4HjV3VWItdvd3z9O4kwLD4M5ucxHE8CulhUyKJrP8UyMoahZ0yOQHR7bOmfAVCI9KVjfuzEytdnGEQyGH6acxL19mgW0ZrjwICah7IOIi3zpZRSpUCnDX4GqYXYclqXbGYkzdi5SKGhQtMkr+ebxHxJGxVY+UhgKDwwnyc12RLAwBiyG+XIAzru0RyoyCYBa9DV rsa@example.com';

    public const ECDSA = 'ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBI2I8EdbS3Slp/IN7K845sd1LBKz/KLHrjRe0hIpaREfov0LnaiW6Z+PFrP7Glf+rqpkk7fiZ541a2Fe1Qv6wP8= ec@example.com';

    /**
     * @return array<string, array{string, string}>
     */
    public static function validKeys(): array
    {
        return [
            'ed25519' => [self::ED25519, 'SHA256:nvWikPWjXWJFiw36iQyJQvpJbc5NtwfchOzuV9sx2CI'],
            'rsa 2048' => [self::RSA, 'SHA256:QhaP04IKoawE3eDZjXeTmwg4427IMxyJ3D715h5aUrE'],
            'ecdsa' => [self::ECDSA, 'SHA256:/N37C0+Hj7f4O1Czq++edftMLQ+QQ14NqeO1auKNbd0'],
        ];
    }

    #[DataProvider('validKeys')]
    public function test_valid_keys_parse_with_the_openssh_fingerprint(string $line, string $fingerprint)
    {
        $key = PublicKey::parse($line);

        $this->assertSame($fingerprint, $key->fingerprint());
        $this->assertSame($line, $key->toString());
    }

    public function test_keys_are_normalized()
    {
        $key = PublicKey::parse("  ssh-ed25519\tAAAAC3NzaC1lZDI1NTE5AAAAIHq0RLG5bN7TQBqYB93lEHfqk4K88b8lHfDZyvpD/FnZ   my   laptop  ");

        $this->assertSame('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHq0RLG5bN7TQBqYB93lEHfqk4K88b8lHfDZyvpD/FnZ my   laptop', $key->toString());
        $this->assertSame('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHq0RLG5bN7TQBqYB93lEHfqk4K88b8lHfDZyvpD/FnZ', PublicKey::parse('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHq0RLG5bN7TQBqYB93lEHfqk4K88b8lHfDZyvpD/FnZ')->toString());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidKeys(): array
    {
        $string = fn (string $value) => pack('N', strlen($value)).$value;
        $rsa1024 = $string('ssh-rsa').$string("\x01\x00\x01").$string("\x00\xC0".str_repeat("\x01", 127));

        return [
            'empty' => [''],
            'private key header' => ['-----BEGIN OPENSSH PRIVATE KEY-----'],
            'dsa' => ['ssh-dss '.base64_encode($string('ssh-dss').$string('x'))],
            'rsa 1024' => ['ssh-rsa '.base64_encode($rsa1024)],
            'type does not match the blob' => ['ssh-rsa AAAAC3NzaC1lZDI1NTE5AAAAIHq0RLG5bN7TQBqYB93lEHfqk4K88b8lHfDZyvpD/FnZ'],
            'not base64' => ['ssh-ed25519 not*base64'],
            'truncated blob' => ['ssh-ed25519 AAAAC3NzaC1lZDI1'],
            'shell injection in the blob' => ['ssh-ed25519 $(reboot)'],
            'control characters' => [self::ED25519."\x00evil"],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function test_invalid_keys_are_refused(string $line)
    {
        $this->expectException(InvalidArgumentException::class);

        PublicKey::parse($line);
    }

    public function test_several_keys_are_parsed_line_by_line()
    {
        $keys = PublicKey::parseMany(self::ED25519."\r\n\n".self::ECDSA."\n");

        $this->assertCount(2, $keys);
        $this->assertSame('ecdsa-sha2-nistp256', $keys[1]->type);
    }

    public function test_one_bad_line_fails_the_whole_text()
    {
        $this->expectException(InvalidArgumentException::class);

        PublicKey::parseMany(self::ED25519."\nhello");
    }
}
