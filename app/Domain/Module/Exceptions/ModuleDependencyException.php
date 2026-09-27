<?php

namespace App\Domain\Module\Exceptions;

use RuntimeException;

class ModuleDependencyException extends RuntimeException
{
    /** @param  list<string>  $missing */
    public static function missing(string $module, array $missing): self
    {
        return new self(sprintf('Module [%s] requires enabled module(s): %s.', $module, implode(', ', $missing)));
    }

    /** @param  list<string>  $dependents */
    public static function requiredBy(string $module, array $dependents): self
    {
        return new self(sprintf('Module [%s] cannot be disabled; required by: %s.', $module, implode(', ', $dependents)));
    }

    /** @param  list<string>  $missing */
    public static function engineRequires(string $engine, array $missing): self
    {
        return new self(sprintf('Engine [%s] requires enabled module(s): %s.', $engine, implode(', ', $missing)));
    }

    /** @param  list<string>  $path */
    public static function circular(array $path): self
    {
        return new self('Circular module dependency: '.implode(' -> ', $path).'.');
    }
}
