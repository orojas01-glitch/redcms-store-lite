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
create/replace preflight. Gate 26E adds an internal reauthorizing product action
runner and a package-owned, value-free product activity ledger. Gate 26F adds a
pure browser-submission decoder for exact create/replace evidence and bounded
simple or variable product fields. The schema, normalizer, persistence boundary,
and administration model support both simple products and bounded variable
products while keeping every business table beneath the
`RED_Addon_StoreLite_` namespace. Gate 26G is defined in
[`docs/PRODUCT-FORM-BRIDGE-CONTRACT.md`](docs/PRODUCT-FORM-BRIDGE-CONTRACT.md).
Its first implementation is a pure typed value adapter for the existing core
form graph. It reserves the bridge for an already-existing product's numeric
package `RecordID` and does not treat a public `ProductID` as a target. Package
0.1.9 now also declares a target-free Add product action and registers the
initial-value loader and atomic creator used by the core-owned Create bridge.
New records start as unavailable drafts and must satisfy the same
simple/variable product contract before creation. Normal richer-package
activation and public commerce behavior remain unavailable.

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
write; a later core-owned, CSRF-protected endpoint must pass the exact plan to
the internal action runner.

The internal action runner consumes the exact preflight plan, repeats the
product capability and plan decision inside the writer-owned transaction, and
records one `product.created` or `product.updated` fact before the same commit.
A changed plan, revoked grant, failed activity insert, or mismatched stored
postcondition rolls back the entire product mutation. Activity rows contain
only product identity, actor identity, event type, state hashes, and time; they
do not copy product titles, descriptions, SKUs, prices, stock, or option data.

The submission decoder accepts no request globals or CSRF token. Core must
authenticate the administrator and consume CSRF before passing the exact
remaining field map. The decoder converts canonical decimal browser strings to
integer minor-unit prices and stock, normalizes the complete product contract,
and refuses unknown fields, ambiguous numbers, recursive structures, and
payloads beyond fixed byte, node, or depth bounds. It does not render a form,
open a database, or invoke the action runner.

The manifest declares the planned Product component, commerce services, and
read-only Products and Orders administrator tools, but normal package
activation remains blocked. Products is operational only for listing, creating,
and editing products through core-owned authenticated/CSRF controls in an
explicitly prepared enabled installation. Orders, the Product component, and
commerce services remain fail-closed placeholders. Runtime catalog-service
invocation, component placement and rendering, cart and order behavior, public
mutations, routes, assets, and payment handling remain later gates.

The RED-CMS core rehearsal stages this package outside the starter in one
fresh disposable schema and records an acceptance-only enabled installation.
Its authenticated desktop/mobile path proves Products -> Add product -> Create
-> reload plus existing target -> Edit -> Save -> reload while preserving a
variable product graph, then removes the server, schema, grant, and staged
package. This does not weaken the normal Owner enablement blockers.

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
php tests/product-form-values-self-test.php
php tests/catalog-administration-submission-self-test.php
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

The submission test is also dependency-free. It proves exact create/replace
evidence, canonical browser-scalar conversion, normalized simple/variable
products, value-free validation errors, CSRF-field separation, and fixed
payload bounds without reading request globals or mutating state.

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
reauthorizing action execution with atomic value-free activity, immediate grant
revocation, plan-substitution refusal, and activity-failure rollback. It then
removes the database and scoped grant. The configured primary database is again
fingerprinted before and after and must remain unchanged.
