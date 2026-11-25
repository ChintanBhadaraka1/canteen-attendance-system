<?php

namespace App\Exceptions;

class StockException extends RecordException
{
    public function __construct(string $message, string $field = 'stock')
    {
        parent::__construct($message, $field);
    }
}
