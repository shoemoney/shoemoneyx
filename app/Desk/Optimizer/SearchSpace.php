<?php

declare(strict_types=1);

namespace App\Desk\Optimizer;

use InvalidArgumentException;

/**
 * A search space is data, not a PHP constant: config/spaces/<name>.json, each key mapping to a
 * list of scalar values (numeric, string, or bool) that DeskOptimize draws candidates from.
 * Every key must start with the required prefix (default "mr.", one strategy per space file).
 * A top-level "_about" string key is allowed for a human note and is stripped on load. Widening
 * a range or adding a knob is now an edit to a JSON file, not a worker image rebuild and
 * optimizer restart.
 */
final readonly class SearchSpace
{
    public function __construct(
        public array $dims,
        public string $name,
        public ?string $path,
    ) {
    }

    /** Load config/spaces/<name>.json. Every key must start with $prefix (default "mr."). */
    public static function named(string $name, string $prefix = 'mr.'): self
    {
        $path = config_path("spaces/{$name}.json");

        if (! is_file($path)) {
            throw new InvalidArgumentException(
                "Unknown search space \"{$name}\". Available: ".implode(', ', self::available()).'.'
            );
        }

        return self::load($path, $name, $prefix);
    }

    /** Load any JSON file, absolute or relative to base_path(). Every key must start with $prefix (default "mr."). */
    public static function fromFile(string $path, string $prefix = 'mr.'): self
    {
        $full = str_starts_with($path, '/') ? $path : base_path($path);

        if (! is_file($full)) {
            throw new InvalidArgumentException("Search space file not found: {$full}");
        }

        return self::load($full, pathinfo($full, PATHINFO_FILENAME), $prefix);
    }

    /** A bare name (or one with no "/" or ".json") resolves via named(); anything else is a file path. */
    public static function resolve(string $spec, string $prefix = 'mr.'): self
    {
        if (str_ends_with($spec, '.json') || str_contains($spec, '/')) {
            return self::fromFile($spec, $prefix);
        }

        return self::named($spec, $prefix);
    }

    /** @return list<string> names available under config/spaces/*.json */
    public static function available(): array
    {
        $names = [];
        foreach (glob(config_path('spaces/*.json')) ?: [] as $file) {
            $names[] = pathinfo($file, PATHINFO_FILENAME);
        }
        sort($names);

        return $names;
    }

    private static function load(string $path, string $name, string $prefix = 'mr.'): self
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new InvalidArgumentException("Could not read search space file: {$path}");
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException("Search space file is not valid JSON: {$path}");
        }

        unset($decoded['_about']);

        foreach ($decoded as $key => $values) {
            $key = (string) $key;

            if (! str_starts_with($key, $prefix)) {
                throw new InvalidArgumentException("Search space key must start with \"{$prefix}\": \"{$key}\" in {$path}");
            }

            if (! is_array($values) || $values === []) {
                throw new InvalidArgumentException("Search space key \"{$key}\" must be a non-empty array in {$path}");
            }

            foreach ($values as $value) {
                if (! is_scalar($value)) {
                    throw new InvalidArgumentException("Search space key \"{$key}\" has a non-scalar value in {$path}");
                }
            }
        }

        return new self($decoded, $name, $path);
    }

    /** Same array shape as the old DeskOptimize::SPACE* constants: key => list of scalar values. */
    public function dims(): array
    {
        return $this->dims;
    }

    /** Override or add dims — for tests. */
    public function with(array $overrides): self
    {
        return new self(array_merge($this->dims, $overrides), $this->name, $this->path);
    }

    /** Keep only the named keys. */
    public function only(array $keys): self
    {
        return new self(array_intersect_key($this->dims, array_flip($keys)), $this->name, $this->path);
    }
}
