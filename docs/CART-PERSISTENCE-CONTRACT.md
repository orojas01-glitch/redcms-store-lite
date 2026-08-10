# Store Lite Cart Persistence Contract

Status: implemented as the internal Store Lite persistence boundary. Store
Lite 0.1.21 adds subject-scoped absolute quantity replacement and line removal
beside the existing add-line operation. Store Lite 0.1.22 binds all three to
separate typed mutations; the new operations still expose no browser control.

## Purpose

This gate gives a future core public-mutation runner a transactional package
operations for adding, setting the exact quantity of, or removing one line in
one anonymous cart. The package owns its business tables; core remains
responsible for public identity issuance, request validation, idempotency,
dispatch, and the enclosing transaction.

## Ownership

The caller passes one positive core-issued numeric `SubjectRecordID`. Store Lite
never reads or persists the raw anonymous token, cookie, request, session, or
response. One unique cart belongs to one subject record.

There is deliberately no foreign key from the package cart to core anonymous
subject storage. Core may expire or rotate that infrastructure record without
silently deleting package business data. A later lifecycle policy must decide
when an abandoned cart may be purged.

## Stored state

`RED_Addon_StoreLite_Carts` stores only the subject relation, installation
currency, and timestamps. `RED_Addon_StoreLite_Cart_Lines` stores exact package
product and optional variant references plus quantity, integer unit price,
currency, integer line total, line identity, and the normalized product-state
hash used to derive the line.

The line identity is a SHA-256 over the product and nullable variant identity.
The cart state is a SHA-256 over the ordered closed set of persisted line facts.
Browser-supplied SKU, price, currency, stock, option labels, totals, or hashes
are never accepted as commercial authority.

`RED_Addon_StoreLite_Cart_Activity` contains only `cart.line.created`,
`cart.line.updated`, `cart.line.quantity-set`, or `cart.line.removed`; numeric
cart and subject relations; line identity; and different before/after
cart-state hashes. It does not duplicate product titles, SKUs, prices, totals,
stock, option labels, or browser values.

## Transaction and concurrency boundary

`RED_CMS_Store_Lite_Cart_Persistence::addLineWithinTransaction()` requires an
already-active caller-owned InnoDB transaction. It never begins, commits, or
rolls back. Any result other than `created` or `updated` requires the caller to
roll back because a late refusal may follow provisional writes.

The operation:

1. locks the subject cart and its current line rows;
2. compares the caller's expected cart-state hash with current storage;
3. locks and reloads the current complete product;
4. resolves the exact product or variant through the server-authoritative
   cart-line resolver;
5. creates or increments the one exact line, bounded to quantity 100;
6. reloads and verifies the complete cart postcondition; and
7. writes one value-free activity fact before returning control to core.

Fresh-state comparison serializes competing captured cart states. Stale state,
unknown commercial input, missing or unavailable products/variants,
insufficient stock, line conflict, partial writes, mismatched postconditions,
or activity failure all fail closed.

Product and variant foreign keys use restrictive deletion. Cart lines cascade
only when their owning cart is explicitly deleted.

`setLineQuantityWithinTransaction()` accepts only the closed 0.1.20 line
handle and integer quantity intent. It locks the line only within the current
subject cart, reloads and locks the current product/variant, and re-runs the
server resolver. The stored price, total, stock decision, and product-state
hash therefore come from current package storage. A request that already
matches the current quantity and commercial state returns `unchanged` without
an activity row.

`removeLineWithinTransaction()` resolves the handle only inside the current
subject cart and deletes that exact row. It deliberately does not require the
referenced product to remain published, available, or otherwise sellable.
Removal writes one value-free activity fact and retains the empty cart row.
A handle that exists only for another subject returns the same
`line_unavailable` result as any absent line.

## Deliberate exclusions

Gate 2C itself did not:

- register or invoke `commerce.cart`;
- expose Add to cart or any other public mutation;
- issue, read, rotate, or expire anonymous browser identity;
- reserve or decrement inventory;
- create an order, checkout, payment, tax, shipping, or fulfillment record; or
- define cart merge, abandonment, or purge policy.

Store Lite 0.1.14 now declares and registers the internal runner binding, but
the browser/server integration remains a separate acceptance gate. See
[`CART-MUTATION-BRIDGE-CONTRACT.md`](CART-MUTATION-BRIDGE-CONTRACT.md).

## Verification

`tests/catalog-migration-self-test.php` applies the seven ordered migrations in
a uniquely named disposable database and proves the exact eleven-table
inventory, cart and placement columns, ownership boundaries, foreign keys, and
value-free activity shape.

`tests/catalog-persistence-self-test.php` proves caller transaction ownership,
simple and explicit-variant writes, additive quantity, fresh/stale state,
anonymous-subject isolation, server-derived money, stock and unknown-field
refusal, absolute quantity replacement, current-product repricing, activity-
free no-op, cross-subject handle refusal, unavailable-product removal, late
activity-failure rollback, catalog deletion protection, scoped cleanup, and
unchanged configured-primary fingerprint.
