<?php

namespace App\Domain\Automation\Support;

final class ActionResult
{
    /** @param  array<string, mixed>  $data */
    private function __construct(
        public readonly bool $skipped,
        public readonly string $message,
        public readonly array $data = [],
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function completed(string $message, array $data = []): self
    {
        return new self(false, $message, $data);
    }

    /** Nothing to do, or nothing that retrying could fix. The run carries on. */
    public static function skipped(string $message, array $data = []): self
    {
        return new self(true, $message, $data);
    }
}
