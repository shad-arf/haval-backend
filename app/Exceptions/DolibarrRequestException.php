<?php

namespace App\Exceptions;

use RuntimeException;

class DolibarrRequestException extends RuntimeException
{
    public function __construct(public readonly int $upstreamStatus)
    {
        parent::__construct('Dolibarr request failed.');
    }
}
