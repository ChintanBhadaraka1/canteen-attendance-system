<?php

namespace App\Exceptions;

use RuntimeException;

// A refusal to show at a form field.
class RecordException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }
}
