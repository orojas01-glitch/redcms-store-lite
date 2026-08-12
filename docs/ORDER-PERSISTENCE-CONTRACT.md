# Store Lite order persistence contract

Status: implemented internally in Store Lite 0.1.26 and linked through the
typed checkout bridge in 0.1.28.

## Purpose

This boundary persists the exact valid result produced by the 0.1.25
guest-order snapshot contract. It does not reserve inventory, contact a payment
provider, or declare that money was received.

## Ownership boundary

RED-CMS core must resolve the current anonymous numeric subject, validate the
future public request and CSRF evidence, enforce rate limits, hash the
core-issued idempotency key, open the database transaction, and decide whether
to commit or roll back. Store Lite accepts only those already-resolved values.

`RED_CMS_Store_Lite_Order_Persistence::proposeWithinTransaction()` locks and
builds the current server-authoritative snapshot, and
`createWithinTransaction()` re-locks, rebuilds, verifies, and writes it. Both:

- requires an active caller-owned transaction;
- reads no request, cookie, session, or runtime global;
- begins, commits, and rolls back no transaction;
- emits no output and registers no route, component, service, or handler;
- invokes no Stripe, PayPal, Zelle, Nequi, wallet, mail, webhook, or other
  external integration.

Any result other than `created` or exact `replayed` requires the caller to roll
back because a late refusal may follow provisional writes.

## Server-authoritative creation

Before writing, the package locks the current subject cart and its lines. It
reloads every current product and selected variant from package storage,
re-runs the cart-line resolver, and requires the stored identity, product-state
hash, quantity, currency, integer price, and integer total to still match.

It then rebuilds the complete 0.1.25 snapshot from the locked server cart,
closed checkout facts recovered from the proposed snapshot, and the current
installation configuration. The rebuilt result must equal the proposed result
exactly. A stale cart or changed product is refused; browser-provided prices,
SKUs, totals, labels, fees, states, or provider references have no write path.

The package generates an opaque `ord_` plus 32 lowercase hexadecimal-character
public order ID on the server. The database record identifier is never returned.

## Atomic write

One successful caller transaction writes:

1. one order header containing source-cart evidence, the subject relation,
   SHA-256 idempotency and snapshot evidence, customer and fulfillment facts,
   payment intent, three distinct initial states, and integer totals;
2. one ordered immutable line snapshot per current cart line;
3. the ordered option-label snapshots for each selected variant;
4. one `order.created` history fact with the same initial states and snapshot
   hash; and
5. deletion of the source cart, whose lines and activity cascade only with that
   consumed cart.

The order header deliberately has no foreign key to expiring core subject
storage or to the consumed cart. Order lines, option labels, and initial history
use restrictive package-owned relationships so deleting an order cannot
silently discard its commercial evidence. Purge remains unavailable.

The class reloads the stored graph and requires it to reproduce the exact
snapshot before returning `created`. A failed insert, missing history fact,
mismatched readback, or cart-consumption failure is a closed result and remains
inside the caller's transaction for rollback.

## Idempotency

The caller supplies lowercase opaque SHA-256 execution evidence issued by core.
For the 0.1.28 bridge this is the request-bound previous-state digest, which is
already keyed by core idempotency evidence and binds command, runtime settings,
subject, and pre-write cart state. It is unique in package storage.

An existing digest returns `replayed` only when the subject, source cart-state
hash, and complete immutable snapshot match exactly and the stored graph passes
the same readback verification. Reusing a digest with different facts returns
`idempotency_conflict`. A different digest cannot create another order after
the source cart has been consumed.

No raw idempotency key is stored or returned.

## Initial payment and lifecycle state

This gate stores intent only:

- pay on receipt: `deferred` and `due_on_receipt`;
- Stripe Checkout, PayPal, and Nequi: `hosted` and `pending`;
- manual Zelle: `manual_transfer` and `pending`.

Every new order begins with order status `pending` and fulfillment status
`unfulfilled`. The database admits only the single initial `order.created`
history fact in 0.1.26. Later lifecycle and provider gates must add their own
bounded migrations and authorization contracts; they must not reinterpret an
initial pending intent as a successful payment.

## Stored-data boundary

All four tables remain beneath `RED_Addon_StoreLite_` and are installed only in
the selected client database:

- `RED_Addon_StoreLite_Orders`
- `RED_Addon_StoreLite_Order_Lines`
- `RED_Addon_StoreLite_Order_Line_Options`
- `RED_Addon_StoreLite_Order_Status_History`

The package source contains no client order, customer, address, payment, or
provider data. The RED-CMS starter receives no deployed package or database
state from this gate.

## Deferred work

Still absent after 0.1.26:

- inventory reservation or decrement;
- payment-provider adapter calls, redirects, webhooks, reconciliation, refunds,
  and paid-state transitions;
- order administration read/write models, fulfillment transitions, customer
  notification, and retention/purge policy.
