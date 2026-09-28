<?php

namespace App\Domain\AI\Support;

use InvalidArgumentException;

/**
 * Prompt templates from resources/prompts/{name}.md. Plain `{{key}}` substitution in one pass, so
 * values (which may contain customer text or `{{…}}` automation placeholders) are never re-substituted.
 */
class PromptLibrary
{
    /** @var array<string, string> */
    private array $templates = [];

    /** @param  array<string, string|int|float|null>  $values */
    public function render(string $name, array $values = []): string
    {
        $template = $this->templates[$name] ??= $this->load($name);
        $pairs = [];

        foreach ($values as $key => $value) {
            $pairs['{{'.$key.'}}'] = (string) ($value ?? '');
        }

        return trim(strtr($template, $pairs));
    }

    private function load(string $name): string
    {
        if (! preg_match('/^[a-z_-]+$/', $name)) {
            throw new InvalidArgumentException("Invalid prompt name [{$name}].");
        }

        $path = resource_path("prompts/{$name}.md");

        if (! is_file($path)) {
            throw new InvalidArgumentException("Prompt [{$name}] does not exist.");
        }

        return (string) file_get_contents($path);
    }
}
