<?php

declare(strict_types=1);

namespace CA\Oid\Services;

use CA\Log\Facades\CaLog;
use CA\Oid\Contracts\OidRegistryInterface;
use CA\Oid\DTOs\OidInfo;
use CA\Oid\Events\OidRegistered;
use CA\Oid\Events\OidUnregistered;
use CA\Oid\Models\OidEntry;
use CA\Oid\Registry\ExtendedValidationOids;
use CA\Oid\Registry\MicrosoftOids;
use CA\Oid\Registry\StandardOids;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class OidRegistry implements OidRegistryInterface
{
    /**
     * In-memory cache of built-in OIDs keyed by OID string.
     *
     * @var array<string, array{name: string, short_name: ?string, description: string, category: string}>
     */
    private array $builtins = [];

    /**
     * Reverse lookup: name -> OID.
     *
     * @var array<string, string>
     */
    private array $nameIndex = [];

    private bool $builtinsLoaded = false;

    public function __construct(
        private readonly OidValidator $validator,
    ) {}

    public function resolve(string $oid): ?OidInfo
    {
        try {
            $oid = $this->format($oid);

            if ($this->cacheEnabled()) {
                $cached = Cache::get($this->cacheKey($oid));
                if ($cached instanceof OidInfo) {
                    CaLog::log('oid_resolve', 'info', "OID resolved from cache: {$oid}", ['oid' => $oid, 'source' => 'cache']);

                    return $cached;
                }
            }

            // Check DB first (custom overrides take precedence)
            $model = $this->findInDatabase($oid);
            if ($model !== null) {
                $info = OidInfo::fromModel($model);
                $this->cacheResult($oid, $info);
                CaLog::log('oid_resolve', 'info', "OID resolved from database: {$oid}", ['oid' => $oid, 'source' => 'database', 'name' => $info->name]);

                return $info;
            }

            // Check built-in registries
            $this->ensureBuiltinsLoaded();
            if (isset($this->builtins[$oid])) {
                $data = $this->builtins[$oid];
                $info = OidInfo::fromArray([
                    'oid' => $oid,
                    'name' => $data['name'],
                    'short_name' => $data['short_name'] ?? null,
                    'description' => $data['description'] ?? null,
                    'category' => $data['category'] ?? 'custom',
                    'is_standard' => true,
                ]);
                $this->cacheResult($oid, $info);
                CaLog::log('oid_resolve', 'info', "OID resolved from builtins: {$oid}", ['oid' => $oid, 'source' => 'builtins', 'name' => $info->name]);

                return $info;
            }

            CaLog::log('oid_resolve', 'info', "OID not found: {$oid}", ['oid' => $oid]);

            return null;
        } catch (\Throwable $e) {
            CaLog::critical($e->getMessage(), ['operation' => 'oid_resolve', 'oid' => $oid, 'exception' => $e::class]);

            throw $e;
        }
    }

    public function resolveByName(string $name): ?OidInfo
    {
        try {
            $name = trim($name);

            if ($this->cacheEnabled()) {
                $cached = Cache::get($this->cacheKey('name:' . $name));
                if ($cached instanceof OidInfo) {
                    CaLog::log('oid_resolve_by_name', 'info', "OID resolved by name from cache: {$name}", ['name' => $name, 'source' => 'cache']);

                    return $cached;
                }
            }

            // Check DB
            $model = OidEntry::query()
                ->where('name', $name)
                ->orWhere('short_name', $name)
                ->first();

            if ($model !== null) {
                $info = OidInfo::fromModel($model);
                $this->cacheResult('name:' . $name, $info);
                CaLog::log('oid_resolve_by_name', 'info', "OID resolved by name from database: {$name}", ['name' => $name, 'source' => 'database', 'oid' => $info->oid]);

                return $info;
            }

            // Check built-in registries
            $this->ensureBuiltinsLoaded();
            if (isset($this->nameIndex[$name])) {
                return $this->resolve($this->nameIndex[$name]);
            }

            // Also check short names in builtins
            foreach ($this->builtins as $oid => $data) {
                if (isset($data['short_name']) && $data['short_name'] === $name) {
                    return $this->resolve($oid);
                }
            }

            CaLog::log('oid_resolve_by_name', 'info', "OID not found by name: {$name}", ['name' => $name]);

            return null;
        } catch (\Throwable $e) {
            CaLog::critical($e->getMessage(), ['operation' => 'oid_resolve_by_name', 'name' => $name, 'exception' => $e::class]);

            throw $e;
        }
    }

    public function register(string $oid, string $name, string $description = '', ?string $category = null): OidEntry
    {
        try {
            $oid = $this->format($oid);

            if (! $this->validator->isValid($oid)) {
                throw new \InvalidArgumentException("Invalid OID format: {$oid}");
            }

            if (! config('ca-oid.allow_custom_oids', true)) {
                throw new \RuntimeException('Custom OID registration is disabled.');
            }

            $entry = OidEntry::updateOrCreate(
                ['oid' => $oid],
                [
                    'name' => $name,
                    'description' => $description,
                    'category' => $category ?? 'custom',
                    'is_standard' => false,
                ],
            );

            $this->invalidateCache($oid);

            OidRegistered::dispatch($entry);

            CaLog::log('oid_register', 'info', "OID registered: {$oid}", ['oid' => $oid, 'name' => $name, 'category' => $category ?? 'custom']);

            return $entry;
        } catch (\Throwable $e) {
            CaLog::critical($e->getMessage(), ['operation' => 'oid_register', 'oid' => $oid, 'name' => $name, 'exception' => $e::class]);

            throw $e;
        }
    }

    public function exists(string $oid): bool
    {
        $oid = $this->format($oid);

        // Check DB
        if (OidEntry::where('oid', $oid)->exists()) {
            return true;
        }

        // Check builtins
        $this->ensureBuiltinsLoaded();

        return isset($this->builtins[$oid]);
    }

    public function search(string $query): Collection
    {
        try {
            $query = trim($query);

            if ($query === '') {
                return collect();
            }

            // Search DB
            $dbResults = OidEntry::search($query)->get()
                ->map(fn (OidEntry $model) => OidInfo::fromModel($model));

            // Search builtins
            $this->ensureBuiltinsLoaded();
            $builtinResults = collect($this->builtins)
                ->filter(function (array $data, string $oid) use ($query) {
                    return str_contains($oid, $query)
                        || stripos($data['name'], $query) !== false
                        || (isset($data['short_name']) && $data['short_name'] !== null && stripos($data['short_name'], $query) !== false)
                        || (isset($data['description']) && stripos($data['description'], $query) !== false);
                })
                ->map(fn (array $data, string $oid) => OidInfo::fromArray([
                    'oid' => $oid,
                    'name' => $data['name'],
                    'short_name' => $data['short_name'] ?? null,
                    'description' => $data['description'] ?? null,
                    'category' => $data['category'] ?? 'custom',
                    'is_standard' => true,
                ]));

            // Merge, DB results take precedence
            $dbOids = $dbResults->pluck('oid')->toArray();

            $results = $dbResults->merge(
                $builtinResults->filter(fn (OidInfo $info) => ! in_array($info->oid, $dbOids, true))
            )->values();

            CaLog::log('oid_search', 'info', "OID search completed for query: {$query}", ['query' => $query, 'results_count' => $results->count()]);

            return $results;
        } catch (\Throwable $e) {
            CaLog::critical($e->getMessage(), ['operation' => 'oid_search', 'query' => $query, 'exception' => $e::class]);

            throw $e;
        }
    }

    public function getByCategory(string $category): Collection
    {
        try {
            $category = trim($category);

            // DB entries
            $dbResults = OidEntry::byCategory($category)->get()
                ->map(fn (OidEntry $model) => OidInfo::fromModel($model));

            // Builtins
            $this->ensureBuiltinsLoaded();
            $builtinResults = collect($this->builtins)
                ->filter(fn (array $data) => ($data['category'] ?? '') === $category)
                ->map(fn (array $data, string $oid) => OidInfo::fromArray([
                    'oid' => $oid,
                    'name' => $data['name'],
                    'short_name' => $data['short_name'] ?? null,
                    'description' => $data['description'] ?? null,
                    'category' => $data['category'] ?? 'custom',
                    'is_standard' => true,
                ]));

            $dbOids = $dbResults->pluck('oid')->toArray();

            $results = $dbResults->merge(
                $builtinResults->filter(fn (OidInfo $info) => ! in_array($info->oid, $dbOids, true))
            )->values();

            CaLog::log('oid_get_by_category', 'info', "OID category lookup: {$category}", ['category' => $category, 'results_count' => $results->count()]);

            return $results;
        } catch (\Throwable $e) {
            CaLog::critical($e->getMessage(), ['operation' => 'oid_get_by_category', 'category' => $category, 'exception' => $e::class]);

            throw $e;
        }
    }

    public function validate(string $oid): bool
    {
        return $this->validator->isValid($oid);
    }

    public function format(string $oid): string
    {
        $oid = trim($oid);
        $oid = ltrim($oid, '.');
        $oid = rtrim($oid, '.');

        // Remove any duplicate dots
        $oid = (string) preg_replace('/\.{2,}/', '.', $oid);

        return $oid;
    }

    public function unregister(string $oid): bool
    {
        try {
            $oid = $this->format($oid);

            $entry = OidEntry::where('oid', $oid)->where('is_standard', false)->first();

            if ($entry === null) {
                CaLog::log('oid_unregister', 'info', "OID not found for unregistration: {$oid}", ['oid' => $oid]);

                return false;
            }

            $entry->delete();
            $this->invalidateCache($oid);

            OidUnregistered::dispatch($oid);

            CaLog::log('oid_unregister', 'info', "OID unregistered: {$oid}", ['oid' => $oid]);

            return true;
        } catch (\Throwable $e) {
            CaLog::critical($e->getMessage(), ['operation' => 'oid_unregister', 'oid' => $oid, 'exception' => $e::class]);

            throw $e;
        }
    }

    /**
     * Load all built-in OID registries into memory.
     */
    public function loadBuiltins(): void
    {
        $sources = [
            StandardOids::getAll(),
            MicrosoftOids::getAll(),
            ExtendedValidationOids::getAll(),
        ];

        foreach ($sources as $source) {
            foreach ($source as $oid => $data) {
                $this->builtins[$oid] = $data;
                $this->nameIndex[$data['name']] = $oid;

                if (isset($data['short_name']) && $data['short_name'] !== null) {
                    $this->nameIndex[$data['short_name']] = $oid;
                }
            }
        }

        $this->builtinsLoaded = true;
    }

    /**
     * Get all available categories from both DB and builtins.
     *
     * @return array<string>
     */
    public function getCategories(): array
    {
        $this->ensureBuiltinsLoaded();

        $builtinCategories = collect($this->builtins)
            ->pluck('category')
            ->unique()
            ->values()
            ->toArray();

        $dbCategories = OidEntry::query()
            ->distinct()
            ->pluck('category')
            ->toArray();

        return array_values(array_unique(array_merge($builtinCategories, $dbCategories)));
    }

    private function ensureBuiltinsLoaded(): void
    {
        if (! $this->builtinsLoaded) {
            $this->loadBuiltins();
        }
    }

    private function findInDatabase(string $oid): ?OidEntry
    {
        return OidEntry::where('oid', $oid)->first();
    }

    private function cacheEnabled(): bool
    {
        return (bool) config('ca-oid.cache_enabled', true);
    }

    private function cacheTtl(): int
    {
        return (int) config('ca-oid.cache_ttl', 86400);
    }

    private function cacheKey(string $key): string
    {
        return 'ca_oid:' . $key;
    }

    private function cacheResult(string $key, OidInfo $info): void
    {
        if ($this->cacheEnabled()) {
            Cache::put($this->cacheKey($key), $info, $this->cacheTtl());
        }
    }

    private function invalidateCache(string $oid): void
    {
        if ($this->cacheEnabled()) {
            Cache::forget($this->cacheKey($oid));
        }
    }
}
