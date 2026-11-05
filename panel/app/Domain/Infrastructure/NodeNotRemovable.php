<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use RuntimeException;

/**
 * Why a node cannot be removed from the panel. The message is an English
 * translation key; `$replace` holds its placeholders.
 */
final class NodeNotRemovable extends RuntimeException
{
    /**
     * @param  array<string, string>  $replace
     */
    public function __construct(string $message, public readonly array $replace = [])
    {
        parent::__construct($message);
    }
}
