# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Integrated `CA\Log` (via `CaLog` facade) for structured logging across all OID service classes.
- `OidRegistry`: logs resolve, resolveByName, register, unregister, search, and getByCategory operations with contextual data.
- `OidResolver`: logs toName, toOid, and getDescription operations.
- `OidValidator`: logs validation results and known-check operations.
- All exceptions in service methods are now logged via `CaLog::critical()` before being re-thrown.
- Added `groupesti/laravel-ca-log` as a required dependency.

## [0.1.0] - 2026-03-29

### Added

- `OidRegistryInterface` and `OidResolverInterface` contracts for registry and resolution operations.
- `CaOid` facade with `resolve()`, `resolveByName()`, `register()`, `exists()`, `search()`, `getByCategory()`, `validate()`, `format()`, and `unregister()` methods.
- `OidRegistry` service for managing OID entries with database persistence and caching.
- `OidResolver` service for bidirectional OID-to-name resolution.
- `OidValidator` service for OID format validation.
- `OidEntry` Eloquent model for database-backed OID storage.
- `OidInfo` DTO for structured OID information.
- Built-in OID collections: `StandardOids`, `ExtendedValidationOids`, and `MicrosoftOids`.
- `OidController` with API routes for programmatic OID lookups.
- `RegisterOidRequest` form request and `OidResource` API resource.
- `ca-oid:seed` Artisan command to populate the database with standard OID collections.
- `ca-oid:list` Artisan command to display all registered OIDs.
- `ca-oid:search` Artisan command to search OIDs by keyword.
- `ca-oid:register` Artisan command to interactively register custom OIDs.
- `ca-oid:resolve` Artisan command to look up a specific OID.
- `OidRegistered` and `OidUnregistered` events.
- Configurable caching (TTL, enable/disable), custom OID allowance, and auto-registration on use.
- Publishable configuration (`ca-oid-config`) and migrations (`ca-oid-migrations`).
