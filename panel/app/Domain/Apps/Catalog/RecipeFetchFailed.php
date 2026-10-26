<?php

declare(strict_types=1);

namespace App\Domain\Apps\Catalog;

use RuntimeException;

/**
 * A source could not be read; the catalog keeps its entries and shows why.
 */
final class RecipeFetchFailed extends RuntimeException {}
