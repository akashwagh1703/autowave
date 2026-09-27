<?php

namespace App\Domain\Module\Exceptions;

use RuntimeException;

class CatalogItemUnavailable extends RuntimeException
{
    public static function module(string $code): self
    {
        return new self("Module [{$code}] does not exist or is not active.");
    }

    public static function engine(string $code): self
    {
        return new self("Engine [{$code}] does not exist or is not active.");
    }
}
