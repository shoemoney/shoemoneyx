#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Regenerates manifest.json for a shoemoneyx-strategies repo from
 * strategies/*.json. Run from the repo root:
 *
 *   php build-strategies-manifest.php
 *
 * or point it at another root:
 *
 *   php ops/build-strategies-manifest.php /path/to/shoemoneyx-strategies
 *
 * Version is derived, not authored: a strategy new to the manifest starts at
 * 1.0.0; one whose file bytes are unchanged since the last manifest keeps its
 * version; one whose sha256 changed gets a patch bump. A strategy file may
 * carry an optional top-level "author" string — the desk's schema validators
 * ignore unknown top-level keys, so this is safe to add without breaking
 * validation. See docs/STRATEGY_SYNC.md for the full manifest spec.
 */
$root = rtrim($argv[1] ?? getcwd(), '/');
$strategiesDir = $root.'/strategies';
$manifestPath = $root.'/manifest.json';

if (! is_dir($strategiesDir)) {
    fwrite(STDERR, "no strategies/ directory at {$strategiesDir}\n");
    exit(1);
}

$previous = [];
if (is_file($manifestPath)) {
    $prevData = json_decode((string) file_get_contents($manifestPath), true);
    foreach ($prevData['strategies'] ?? [] as $entry) {
        if (isset($entry['id'])) {
            $previous[$entry['id']] = $entry;
        }
    }
}

$files = glob($strategiesDir.'/*.json') ?: [];
sort($files);

$strategies = [];
$errors = [];

foreach ($files as $path) {
    $raw = (string) file_get_contents($path);
    $definition = json_decode($raw, true);
    $relPath = 'strategies/'.basename($path);

    if (! is_array($definition)) {
        $errors[] = "{$relPath}: not valid JSON";

        continue;
    }

    $id = $definition['key'] ?? null;
    if (! is_string($id) || ! preg_match('/^[a-z0-9-]{2,64}$/', $id)) {
        $errors[] = "{$relPath}: missing or invalid \"key\" (used as the manifest id)";

        continue;
    }

    $meta = $definition['meta'] ?? [];
    $name = $meta['name'] ?? $definition['name'] ?? null;
    if (! is_string($name) || trim($name) === '') {
        $errors[] = "{$relPath}: missing meta.name";

        continue;
    }

    $sha256 = hash('sha256', $raw);
    $prev = $previous[$id] ?? null;
    $version = match (true) {
        $prev === null => '1.0.0',
        ($prev['sha256'] ?? null) === $sha256 => $prev['version'] ?? '1.0.0',
        default => bump_patch($prev['version'] ?? '1.0.0'),
    };

    $strategies[] = [
        'id' => $id,
        'name' => $name,
        'version' => $version,
        'file' => $relPath,
        'sha256' => $sha256,
        'tags' => array_values((array) ($meta['tags'] ?? [])),
        'timeframe' => $meta['timeframe'] ?? null,
        'assets' => array_values((array) ($meta['assets'] ?? [])),
        'author' => $definition['author'] ?? 'community',
        'description' => $meta['description'] ?? $definition['description'] ?? '',
        'min_desk_schema' => $definition['schema_version'] ?? 1,
    ];
}

if ($errors) {
    fwrite(STDERR, implode("\n", $errors)."\n");
    exit(1);
}

usort($strategies, fn (array $a, array $b) => $a['id'] <=> $b['id']);

$manifest = [
    'schema_version' => 1,
    'generated_at' => gmdate('c'),
    'strategies' => $strategies,
];

file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

fwrite(STDOUT, count($strategies)." strategies written to {$manifestPath}\n");

function bump_patch(string $version): string
{
    if (! preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $version, $m)) {
        return '1.0.0';
    }

    return $m[1].'.'.$m[2].'.'.((int) $m[3] + 1);
}
