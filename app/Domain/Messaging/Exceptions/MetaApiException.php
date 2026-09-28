<?php

namespace App\Domain\Messaging\Exceptions;

use RuntimeException;

/** A Graph API error. The message is Meta's error text; it never contains the access token. */
class MetaApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?int $metaCode = null,
    ) {
        parent::__construct($message, $status);
    }

    /** 4xx errors (other than rate limits) will fail again on retry: bad number, closed window, bad template, bad token. */
    public function isPermanent(): bool
    {
        return $this->status >= 400 && $this->status < 500 && $this->status !== 429 && ! in_array($this->metaCode, [4, 80007, 130429, 131048, 131056], true);
    }
}
