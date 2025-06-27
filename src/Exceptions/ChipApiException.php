<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Exceptions;

use Exception;

class ChipApiException extends Exception
{
    /**
     * Create a new Chip API exception instance.
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the exception as a string representation.
     */
    public function __toString(): string
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
} 