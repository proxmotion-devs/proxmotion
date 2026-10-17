<?php

declare(strict_types=1);

namespace Proxmotion\Wisecp\Tests;

use Proxmotion\Wisecp\Transport;
use WISECP\Modules\Servers\Proxmotion;

/**
 * The module with the Proxmotion API faked and without waiting.
 */
final class TestProxmotion extends Proxmotion
{
    public static ?FakeTransport $api = null;

    protected function transport(): Transport
    {
        return self::$api ??= new FakeTransport;
    }

    protected function sleep(int $seconds): void {}
}
