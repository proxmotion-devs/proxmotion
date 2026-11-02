<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * resources/views/vendor/ holds copies of Laravel's mail views with only the
 * panel's name in place of APP_NAME (docs/adr/0053). When a Laravel update
 * changes the originals, this fails: copy them again and swap the name back.
 */
class BrandedMailViewsTest extends TestCase
{
    private const string HEADER = "{{-- Laravel's view with the panel's name from Panel settings instead of APP_NAME (docs/adr/0053). --}}\n";

    /**
     * @return array<string, array{string, string}>
     */
    public static function views(): array
    {
        $framework = dirname(__DIR__, 2).'/vendor/laravel/framework/src/Illuminate';
        $copies = dirname(__DIR__, 2).'/resources/views/vendor';

        return [
            'mail html' => ["{$framework}/Mail/resources/views/html/message.blade.php", "{$copies}/mail/html/message.blade.php"],
            'mail text' => ["{$framework}/Mail/resources/views/text/message.blade.php", "{$copies}/mail/text/message.blade.php"],
            'notification' => ["{$framework}/Notifications/resources/views/email.blade.php", "{$copies}/notifications/email.blade.php"],
        ];
    }

    #[DataProvider('views')]
    public function test_the_copy_differs_from_laravel_only_in_the_name(string $original, string $copy)
    {
        $normalize = fn (string $path): string => str_replace("\r\n", "\n", (string) file_get_contents($path));

        $expected = self::HEADER.str_replace("{{ config('app.name') }}", '{{ \App\Domain\Settings\Branding::name() }}', $normalize($original));

        $this->assertSame($expected, $normalize($copy));
        $this->assertStringNotContainsString("config('app.name')", $normalize($copy));
    }
}
