<?php

declare(strict_types=1);

namespace App\Support;

/** Structural diff between two decoded-JSON trees, reported as dot-path leaves. */
final class JsonDiff
{
    /**
     * @return array{
     *     added: array<int, array{path: string, value: mixed}>,
     *     removed: array<int, array{path: string, value: mixed}>,
     *     changed: array<int, array{path: string, from: mixed, to: mixed}>,
     * }
     */
    public static function diff(array $a, array $b): array
    {
        $flatA = self::flatten($a);
        $flatB = self::flatten($b);

        $added = [];
        $changed = [];
        foreach ($flatB as $path => $value) {
            if (! array_key_exists($path, $flatA)) {
                $added[] = ['path' => $path, 'value' => $value];
            } elseif ($flatA[$path] !== $value) {
                $changed[] = ['path' => $path, 'from' => $flatA[$path], 'to' => $value];
            }
        }

        $removed = [];
        foreach ($flatA as $path => $value) {
            if (! array_key_exists($path, $flatB)) {
                $removed[] = ['path' => $path, 'value' => $value];
            }
        }

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
    }

    /**
     * Leaf-only dot-path flattening. An empty array is a leaf (so "became empty"
     * shows up as changed/added, not silently dropped); a non-empty array or
     * object recurses one path segment per key.
     *
     * @return array<string, mixed>
     */
    private static function flatten(array $arr, string $prefix = ''): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            $path = $prefix === '' ? (string) $k : "{$prefix}.{$k}";
            if (is_array($v) && $v !== []) {
                $out += self::flatten($v, $path);
            } else {
                $out[$path] = $v;
            }
        }

        return $out;
    }
}
