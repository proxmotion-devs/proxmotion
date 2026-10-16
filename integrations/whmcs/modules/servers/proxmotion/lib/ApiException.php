<?php

namespace Proxmotion\Whmcs;

use RuntimeException;

/**
 * The panel refused a request or could not be reached. The message is meant
 * for administrators (WHMCS shows it after module commands).
 */
class ApiException extends RuntimeException
{
    /** @var int HTTP status, 0 when the panel could not be reached */
    private $status;

    /** @var array<string, list<string>> validation errors per field */
    private $errors;

    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(string $message, int $status = 0, array $errors = [])
    {
        parent::__construct($message, $status);
        $this->status = $status;
        $this->errors = $errors;
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
