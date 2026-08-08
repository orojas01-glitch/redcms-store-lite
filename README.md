# RED-CMS Store Lite

Store Lite is an optional, separately distributed first-party package for
RED-CMS 5.1. It is not part of the clean RED-CMS starter and must be deployed
independently for each client installation.

## Current status

Gate 25 established the separate package foundation. Gate 26A adds immutable
catalog migrations for products, option groups, option values, explicit variants,
and variant selections. Gate 26B adds a package-owned pure normalizer that
enforces the approved simple/variable product contract before any write. Gate
26C adds the internal package-owned catalog reader and atomic create/replace
writer. Gate 26D adds a read-only, permission-scoped product administration
model with bounded cursor pagination, exact edit loading, and deterministic
create/replace preflight. The schema, normalizer, persistence boundary, and
administration model support both simple products and bounded variable products
while keeping every business table beneath the `RED_Addon_StoreLite_` namespace.

Catalog creation refuses an existing product ID. Replacement requires the exact
SHA-256 of the current normalized product state and refuses stale input. Each
write owns its transaction, reloads the complete stored product graph before
commit, and rolls back on a partial write or mismatched postcondition. It does
not expose SQL diagnostics or accept a caller-owned transaction.

The administration model requires a fresh exact `store.products.manage`
capability decision from RED-CMS. Owner or add-on lifecycle access does not
substitute for that grant. It returns no partial catalog on authorization,
storage, cursor, or reconstruction failure. Its create/replace plans bind the
actor, product identity, current state, and normalized target state but never
write; a later CSRF-protected action must reauthorize and revalidate the plan
before invoking the atomic writer.

The manifest declares the planned Product component, commerce services, and
read-only Products and Orders administrator tools, but the package remains
intentionally non-operational and activation-blocked. Both tool handlers remain
fail-closed placeholders. Operational administrator forms/actions, runtime
catalog-service registration, component placement and rendering, cart and order
behavior, public mutations, routes, assets, and payment handling remain later
gates.

The entry point registers fail-closed placeholders. Any attempted invocation
throws before business behavior can occur.

## Distribution boundary

- `package/` is the exact package payload.
- Deploy that payload beneath a client's
  `addons/redcms/store-lite/` directory.
- Never copy this repository into the RED-CMS clean starter.
- Install package migrations only in that client's database.
- Keep products, orders, settings, secrets, media, and other business state in
  the client installation, never in this source repository.
- Disabling Store Lite must retain package data. Purge is not available in this
  foundation.

## Foundation verification

Set `RED_CMS_CORE_ROOT` when the RED-CMS checkout is not the default sibling
directory, then run:

```sh
php tests/package-foundation-self-test.php
php tests/product-normalizer-self-test.php
php tests/catalog-migration-self-test.php
php tests/catalog-persistence-self-test.php
```

The foundation test stages the payload under a disposable project root,
validates it with the RED-CMS 5.1 non-executing manifest contract, proves the
richer package remains activation-blocked, and confirms the core checkout still
has no deployed `addons/` directory.

The normalizer test has no database, request, runtime, filesystem, or network
dependency. It proves canonical simple/variable records and fail-closed refusal
of malformed, stale, duplicate, unbounded, or mixed product data.

The catalog test uses a uniquely named disposable MySQL database. It grants the
configured application account access only to that database, applies the exact
ordered manifest migrations, proves the simple and variable product constraints,
and removes the database and grant. The configured primary database is
fingerprinted before and after and must remain unchanged.

The persistence test requires a PHP CLI runtime with `mysqli`. It creates a
second uniquely named disposable database, exercises exact simple and variable
product reloads, stale-state refusal, atomic replacement, forced rollback,
caller-owned transaction refusal, exact product permission isolation, bounded
catalog pages, full edit loading, non-writing create/replace preflight, and
immediate grant revocation. It then removes the database and scoped grant. The
configured primary database is again fingerprinted before and after and must
remain unchanged.
