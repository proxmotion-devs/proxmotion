<?php

declare(strict_types=1);

namespace Proxmotion\Wisecp;

use RuntimeException;

/**
 * The panel refused a request or could not be reached. The message is meant
 * for administrators (WISECP shows it after module actions).
 */
final class ApiException extends RuntimeException
{
    /**
     * @param int $status HTTP status, 0 when the panel could not be reached
     * @param array<string, list<string>> $errors validation errors per field
     */
    public function __construct(string $message, private readonly int $status = 0, private readonly array $errors = [])
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The message with the first validation error of each field, e.g.
     * "The given data was invalid. (hostname: The hostname must be valid.)".
     */
    public function describe(): string
    {
        $details = [];

        foreach ($this->errors as $field => $messages) {
            if (isset($messages[0])) {
                $details[] = $field . ': ' . $messages[0];
            }
        }

        return $details === [] ? $this->getMessage() : $this->getMessage() . ' (' . implode('; ', $details) . ')';
    }
}
