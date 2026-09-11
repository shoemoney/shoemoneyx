<?php

declare(strict_types=1);

namespace App\Strategies\Sync;

use App\Desk\Strategies\JsonPluginValidator;
use App\Desk\Strategies\SchemaMigrator;
use App\Desk\Strategies\StrategySchemaValidator;
use App\Models\StrategyPlugin;
use App\Models\SyncedStrategy;
use App\Support\SemVer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Pulls community strategies from the public repo at config('strategies.source_url')
 * — manifest.json plus one JSON file per strategy, see docs/STRATEGY_SYNC.md.
 *
 * check() is read-only against the remote but writes bookkeeping (SyncedStrategy
 * remote_version/sha256/seen_at) so repeated calls stay idempotent and cheap to
 * cache. import() is the only thing that ever touches StrategyPlugin — it never
 * overwrites a plugin's current row destructively: a first import creates the
 * plugin at 1.0.0 (or the remote's own version, when it's valid semver); every
 * later import of that remote_id adds a NEW version, exactly like a manual save
 * from the builder, and flags `local_modified` when the plugin's definition has
 * drifted from what sync last wrote — the caller decides what to do with that,
 * this class never silently discards an operator's edits.
 */
final class StrategySync
{
    private const CREATED_BY = 'strategy-sync';

    public function __construct(private ?string $sourceUrl = null)
    {
        $this->sourceUrl = rtrim($sourceUrl ?? (string) config('strategies.source_url'), '/').'/';
    }

    /** @return array{new: list<array>, updated: list<array>, up_to_date: int} */
    public function check(): array
    {
        $entries = $this->verifiedEntries($this->fetchManifest());

        $new = [];
        $updated = [];
        $upToDate = 0;

        foreach ($entries as $entry) {
            $row = SyncedStrategy::firstOrNew(['remote_id' => $entry['id']]);
            $wasImported = $row->exists && $row->strategy_plugin_id !== null;

            if (! $wasImported) {
                $new[] = $entry;
            } elseif ($row->sha256 !== $entry['sha256']) {
                $updated[] = $entry;
            } else {
                $upToDate++;
            }

            $row->remote_id = $entry['id'];
            $row->remote_version = $entry['version'];
            $row->sha256 = $entry['sha256'];
            $row->seen_at = now();
            $row->save();
        }

        return ['new' => $new, 'updated' => $updated, 'up_to_date' => $upToDate];
    }

    /** @return array{imported: bool, error?: string, errors?: array, local_modified?: bool, plugin?: StrategyPlugin, remote_id?: string} */
    public function import(string $remoteId): array
    {
        $entry = $this->findEntry($remoteId);
        if ($entry === null) {
            return ['imported' => false, 'error' => "no such remote strategy: {$remoteId}"];
        }

        $content = $this->fetchFile($entry['file']);
        $actualSha = hash('sha256', $content);
        if (! hash_equals($entry['sha256'], $actualSha)) {
            return ['imported' => false, 'error' => "sha256 mismatch for {$remoteId}: manifest says {$entry['sha256']}, file hashes to {$actualSha}"];
        }

        $definition = json_decode($content, true);
        if (! is_array($definition)) {
            return ['imported' => false, 'error' => "{$remoteId}: remote file is not valid JSON"];
        }

        $result = isset($definition['schema_version'])
            ? StrategySchemaValidator::validate($definition)
            : JsonPluginValidator::validate($definition);
        if (! $result['valid']) {
            return ['imported' => false, 'error' => "{$remoteId}: schema validation failed", 'errors' => $result['errors']];
        }

        return DB::transaction(function () use ($definition, $entry) {
            $row = SyncedStrategy::firstOrNew(['remote_id' => $entry['id']]);
            $localModified = false;

            if ($row->strategy_plugin_id === null) {
                $plugin = $this->createPlugin($definition, $entry);
            } else {
                $plugin = StrategyPlugin::findOrFail($row->strategy_plugin_id);
                $lastSyncedHash = $row->local_definition_sha256;
                $localModified = $lastSyncedHash !== null && $lastSyncedHash !== hash('sha256', json_encode($plugin->definition));
                $plugin = $this->addVersion($plugin, $definition, $entry);
            }

            $row->remote_id = $entry['id'];
            $row->remote_version = $entry['version'];
            $row->sha256 = $entry['sha256'];
            $row->strategy_plugin_id = $plugin->id;
            $row->imported_at = now();
            $row->local_definition_sha256 = hash('sha256', json_encode($definition));
            $row->seen_at = now();
            $row->save();

            return [
                'imported' => true,
                'local_modified' => $localModified,
                'plugin' => $plugin->fresh(),
                'remote_id' => $entry['id'],
            ];
        });
    }

    /** Import every strategy check() finds new (never before imported). */
    public function importAll(): array
    {
        return array_map(fn (array $entry) => $this->import($entry['id']), $this->check()['new']);
    }

    private function createPlugin(array $definition, array $entry): StrategyPlugin
    {
        $version = $this->resolveVersion($entry['version'], null);
        $meta = $definition['meta'] ?? [];

        $plugin = StrategyPlugin::create([
            'key' => $definition['key'] ?? $entry['id'],
            'name' => $meta['name'] ?? $definition['name'] ?? $entry['name'],
            'description' => $meta['description'] ?? $definition['description'] ?? $entry['description'] ?? null,
            'definition' => $definition,
            'current_version' => $version,
        ]);

        $plugin->versions()->create([
            'version' => $version,
            'definition' => $definition,
            'changelog' => "imported {$entry['version']}",
            'created_by' => self::CREATED_BY,
        ]);

        return $plugin;
    }

    private function addVersion(StrategyPlugin $plugin, array $definition, array $entry): StrategyPlugin
    {
        $version = $this->resolveVersion($entry['version'], $plugin->current_version);
        $meta = $definition['meta'] ?? [];

        $plugin->update([
            'name' => $meta['name'] ?? $definition['name'] ?? $plugin->name,
            'description' => $meta['description'] ?? $definition['description'] ?? $plugin->description,
            'definition' => $definition,
            'current_version' => $version,
        ]);

        $plugin->versions()->create([
            'version' => $version,
            'definition' => $definition,
            'changelog' => "synced {$entry['version']}",
            'created_by' => self::CREATED_BY,
        ]);

        return $plugin;
    }

    /**
     * Use the remote's own version when it's valid semver and would not collide
     * with the plugin's current version (strategy_plugin_versions is unique on
     * [plugin_id, version]); otherwise patch-bump off the current version, or
     * 1.0.0 for a brand-new plugin.
     */
    private function resolveVersion(string $remoteVersion, ?string $currentVersion): string
    {
        $isSemver = (bool) preg_match('/^\d+\.\d+\.\d+$/', $remoteVersion);
        if ($isSemver && $remoteVersion !== $currentVersion) {
            return $remoteVersion;
        }

        return $currentVersion ? SemVer::bump($currentVersion, 'patch') : '1.0.0';
    }

    private function findEntry(string $remoteId): ?array
    {
        foreach ($this->verifiedEntries($this->fetchManifest()) as $entry) {
            if ($entry['id'] === $remoteId) {
                return $entry;
            }
        }

        return null;
    }

    /** @return array<int, array> */
    private function fetchManifest(): array
    {
        $response = Http::timeout(20)->get($this->sourceUrl.'manifest.json');
        if (! $response->successful()) {
            throw new \RuntimeException("failed to fetch manifest.json: HTTP {$response->status()}");
        }

        $data = $response->json();
        if (! is_array($data) || ($data['schema_version'] ?? null) !== 1 || ! is_array($data['strategies'] ?? null)) {
            throw new \RuntimeException('manifest.json is malformed (expected {schema_version: 1, strategies: [...]})');
        }

        return $data['strategies'];
    }

    private function fetchFile(string $path): string
    {
        $response = Http::timeout(20)->get($this->sourceUrl.ltrim($path, '/'));
        if (! $response->successful()) {
            throw new \RuntimeException("failed to fetch {$path}: HTTP {$response->status()}");
        }

        return $response->body();
    }

    /**
     * Drop manifest entries with the wrong shape or a min_desk_schema this desk
     * can't read, instead of failing the whole sync over one bad entry.
     *
     * @return list<array>
     */
    private function verifiedEntries(array $entries): array
    {
        $verified = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (! isset($entry['id']) || ! is_string($entry['id']) || ! preg_match('/^[a-z0-9-]{2,64}$/', $entry['id'])) {
                continue;
            }
            foreach (['name', 'version', 'file', 'sha256'] as $key) {
                if (! isset($entry[$key]) || ! is_string($entry[$key]) || $entry[$key] === '') {
                    continue 2;
                }
            }
            if (! preg_match('/^[a-f0-9]{64}$/', $entry['sha256'])) {
                continue;
            }
            if ((int) ($entry['min_desk_schema'] ?? 1) > SchemaMigrator::CURRENT_VERSION) {
                continue;
            }

            $verified[] = [
                'id' => $entry['id'],
                'name' => $entry['name'],
                'version' => $entry['version'],
                'file' => $entry['file'],
                'sha256' => $entry['sha256'],
                'tags' => array_values(array_filter((array) ($entry['tags'] ?? []), 'is_string')),
                'timeframe' => is_string($entry['timeframe'] ?? null) ? $entry['timeframe'] : null,
                'assets' => array_values(array_filter((array) ($entry['assets'] ?? []), 'is_string')),
                'author' => is_string($entry['author'] ?? null) ? $entry['author'] : 'community',
                'description' => is_string($entry['description'] ?? null) ? $entry['description'] : '',
                'min_desk_schema' => (int) ($entry['min_desk_schema'] ?? 1),
            ];
        }

        return $verified;
    }
}
