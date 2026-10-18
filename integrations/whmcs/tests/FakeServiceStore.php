<?php

namespace Proxmotion\Whmcs\Tests;

use Proxmotion\Whmcs\ServiceStore;

/**
 * The WHMCS data the addon hooks read, in memory.
 */
class FakeServiceStore implements ServiceStore
{
    /** @var array<string, mixed>|null */
    public $params;

    /** @var list<array{message: string, userid: int}> */
    public $logged = [];

    public function moduleParams(int $serviceId): ?array
    {
        return $this->params;
    }

    public function log(string $message, int $userId): void
    {
        $this->logged[] = ['message' => $message, 'userid' => $userId];
    }
}
