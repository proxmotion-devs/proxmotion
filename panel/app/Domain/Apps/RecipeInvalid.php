<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use RuntimeException;

/**
 * A recipe directory that does not pass validation (apps:validate).
 */
final class RecipeInvalid extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly string $slug, public readonly array $errors)
    {
        parent::__construct("Recipe {$slug} is invalid: ".implode('; ', $errors));
    }
}
