<?php

declare(strict_types=1);

namespace CA\Oid\Services;

use CA\Log\Facades\CaLog;
use CA\Oid\Contracts\OidResolverInterface;

final class OidResolver implements OidResolverInterface
{
    public function __construct(
        private readonly OidRegistry $registry,
    ) {}

    public function toName(string $oid): ?string
    {
        try {
            $info = $this->registry->resolve($oid);
            CaLog::log('oid_to_name', 'info', "OID resolved to name: {$oid}", ['oid' => $oid, 'name' => $info?->name]);

            return $info?->name;
        } catch (\Throwable $e) {
            CaLog::critical($e->getMessage(), ['operation' => 'oid_to_name', 'oid' => $oid, 'exception' => $e::class]);

            throw $e;
        }
    }

    public function toOid(string $name): ?string
    {
        try {
            $info = $this->registry->resolveByName($name);
            CaLog::log('oid_to_oid', 'info', "Name resolved to OID: {$name}", ['name' => $name, 'oid' => $info?->oid]);

            return $info?->oid;
        } catch (\Throwable $e) {
            CaLog::critical($e->getMessage(), ['operation' => 'oid_to_oid', 'name' => $name, 'exception' => $e::class]);

            throw $e;
        }
    }

    public function getDescription(string $oid): ?string
    {
        try {
            $info = $this->registry->resolve($oid);
            CaLog::log('oid_get_description', 'info', "OID description resolved: {$oid}", ['oid' => $oid, 'description' => $info?->description]);

            return $info?->description;
        } catch (\Throwable $e) {
            CaLog::critical($e->getMessage(), ['operation' => 'oid_get_description', 'oid' => $oid, 'exception' => $e::class]);

            throw $e;
        }
    }
}
