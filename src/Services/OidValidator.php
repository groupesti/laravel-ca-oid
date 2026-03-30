<?php

declare(strict_types=1);

namespace CA\Oid\Services;

use CA\Log\Facades\CaLog;

final class OidValidator
{
    public function __construct(
        private readonly ?OidRegistry $registry = null,
    ) {}

    /**
     * Validate OID format: dot-separated non-negative integers,
     * first arc 0-2, second arc <= 39 for arcs 0 and 1.
     */
    public function isValid(string $oid): bool
    {
        $oid = trim($oid);

        if ($oid === '') {
            CaLog::log('oid_validation', 'info', 'OID validation failed: empty string', ['oid' => $oid, 'valid' => false]);

            return false;
        }

        // Must be dot-separated non-negative integers
        if (! preg_match('/^\d+(\.\d+)*$/', $oid)) {
            CaLog::log('oid_validation', 'info', "OID validation failed: invalid format: {$oid}", ['oid' => $oid, 'valid' => false]);

            return false;
        }

        $arcs = explode('.', $oid);

        // Must have at least 2 arcs
        if (count($arcs) < 2) {
            CaLog::log('oid_validation', 'info', "OID validation failed: insufficient arcs: {$oid}", ['oid' => $oid, 'valid' => false]);

            return false;
        }

        $firstArc = (int) $arcs[0];
        $secondArc = (int) $arcs[1];

        // First arc must be 0, 1, or 2
        if ($firstArc < 0 || $firstArc > 2) {
            CaLog::log('oid_validation', 'info', "OID validation failed: invalid first arc: {$oid}", ['oid' => $oid, 'valid' => false]);

            return false;
        }

        // For arcs 0 and 1, second arc must be <= 39
        if ($firstArc <= 1 && $secondArc > 39) {
            CaLog::log('oid_validation', 'info', "OID validation failed: invalid second arc: {$oid}", ['oid' => $oid, 'valid' => false]);

            return false;
        }

        CaLog::log('oid_validation', 'info', "OID validated: {$oid}", ['oid' => $oid, 'valid' => true]);

        // All arcs must be non-negative (already ensured by regex)
        return true;
    }

    /**
     * Check whether the OID exists in the registry.
     */
    public function isKnown(string $oid): bool
    {
        if ($this->registry === null) {
            return false;
        }

        $known = $this->registry->exists($oid);
        CaLog::log('oid_known_check', 'info', "OID known check: {$oid}", ['oid' => $oid, 'known' => $known]);

        return $known;
    }

    /**
     * Extract a specific arc from an OID.
     */
    public function getArc(string $oid, int $index): ?int
    {
        if (! $this->isValid($oid)) {
            return null;
        }

        $arcs = explode('.', trim($oid));

        return isset($arcs[$index]) ? (int) $arcs[$index] : null;
    }

    /**
     * Get the parent OID by removing the last arc.
     */
    public function getParent(string $oid): ?string
    {
        if (! $this->isValid($oid)) {
            return null;
        }

        $arcs = explode('.', trim($oid));

        if (count($arcs) <= 2) {
            return null;
        }

        array_pop($arcs);

        return implode('.', $arcs);
    }

    /**
     * Check if the OID is a child (descendant) of the given parent OID.
     */
    public function isChildOf(string $oid, string $parent): bool
    {
        $oid = trim($oid);
        $parent = trim($parent);

        if (! $this->isValid($oid) || ! $this->isValid($parent)) {
            return false;
        }

        return str_starts_with($oid, $parent . '.') && $oid !== $parent;
    }

    /**
     * Get the depth (number of arcs) of an OID.
     */
    public function getDepth(string $oid): int
    {
        if (! $this->isValid($oid)) {
            return 0;
        }

        return count(explode('.', trim($oid)));
    }
}
