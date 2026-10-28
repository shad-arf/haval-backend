<?php

namespace App\Exceptions;

use RuntimeException;

class DolibarrRequestException extends RuntimeException
{
    public function __construct(public readonly int $upstreamStatus, ?string $upstreamMessage = null)
    {
        $detail = trim(strip_tags($upstreamMessage ?? ''));

        parent::__construct($detail === '' ? 'Dolibarr request failed.' : 'Dolibarr: '.mb_substr($detail, 0, 500));
    }
}
