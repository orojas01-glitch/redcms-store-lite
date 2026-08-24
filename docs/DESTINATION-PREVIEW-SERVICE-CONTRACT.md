# Store Lite Destination Preview Service Contract

Version: 0.1.41
Service: `content.destination-preview.store-lite`
Operation: `destination.preview`

## Purpose

This package-owned typed service rederives the current Store Lite product
destination preview for RED-CMS's restartable destination coordinator. It is a
read-only evidence boundary, not provisioning authority.

## Input

The request contains exactly:

- `productId`: canonical public Store Lite Product ID;
- `currency`: the installation's uppercase three-letter catalog currency;
- `language`: the exact two-letter lowercase destination language.

No actor, database record identifier, route identifier, component identifier,
price, stock, request global, session, token, secret, or client identifier is
accepted.

## Current-state derivation

The service opens a separate database connection and starts one read-only
transaction. Within that snapshot it:

1. loads the current product through Store Lite catalog persistence;
2. derives current destination status from Product placement and Article-route
   evidence;
3. rebuilds the deterministic provisioning preview;
4. rolls back the read-only transaction and closes the connection.

Missing products, invalid input, unavailable storage, malformed current state,
and unsupported operations return bounded typed failure codes without data.

After RED-CMS has committed the first route stage, the service authorizes
continuation only when the requested language has exactly one active, visible,
root-home Article route for the Product alias, the alias is globally unique,
and the Product has no component placement. That state is projected internally
as `route_created` and hashes to the same normalized `provisioning` phase as
the original clean `missing` state. Duplicate, cross-language, inactive,
nested, already placed, or otherwise partial states remain repair-only.

## Output

A successful call returns exactly:

- `schema` (`1`);
- `planSha256`;
- `intent`;
- `ready`;
- `requiresConfirmation`;
- `writesEnabled` (always `false`);
- `path`.

Operator labels, explanatory copy, blocker lists, operation lists, product
state, product/database record IDs, commercial values, inventory, media, cart,
order, payment, customer, administrator, settings, and secrets are excluded.

## Operational boundary

Registration alone does not expose an HTTP route, administrator action, or
public mutation. The service does not allocate IDs, create an Article or
component, publish content, update Store Lite tables, write an audit, notify
search, enable a package, or deploy anything. RED-CMS retains authorization,
plan confirmation, locks, content transactions, revisions, audit, checkpoint,
and later post-commit search authority.

## Verification

The dependency-free service test passes seven envelope, exclusion, path, and
source assertions. The existing fresh-database lifecycle wrapper also runs a
24-assertion real-service/core-route rehearsal after Store Lite is re-enabled.
It creates
one synthetic published product only when needed, receives `provision` with
`writesEnabled: false`, proves missing-product and invalid-currency failures,
proves the service leaves its complete database fingerprint unchanged, invokes
the merged core route writer with server-derived IDs, verifies the exact
Article/revision/audit/checkpoint set, rederives the same package plan from the
guarded route-only state, resumes without duplicate writes, removes the
synthetic route and product fixtures, and restores the pre-test fingerprint
before the
wrapper verifies database/grant/project/process cleanup and unchanged primary
state.
