# Store Lite Payment-Event Service Contract

Status: P3B-3 is implemented in Store Lite 0.1.34. The separately installed
Store Lite package now owns one typed `commerce.orders` operation, but no
adapter, webhook route, provider credential, provider request, or client
deployment exists in this batch.

## Runtime Ownership

RED-CMS request bootstrap loads only current enabled packages whose manifest
and integrity evidence pass. Its typed service boundary resolves exactly one
request-local owner for `commerce.orders`; the Store Lite registrar binds that
identifier to `RED_CMS_Store_Lite_Payment_Event_Service::handle()`.

The only operational order-service operation is `payment.event.apply`. Its
input has exactly two objects:

- `order`: the closed P3B current order projection; and
- `event`: the already-verified provider-neutral P0 event.

An additional key or raw provider field fails closed. The service returns only
typed status, opaque event and plan hashes, state-change and fulfillment-block
flags, and the resulting three Store Lite states. It never returns customer,
address, line-item, database-record, credential, provider-event, or error-body
data.

## Transaction Split

`RED_CMS_Store_Lite_Payment_Event_Persistence::applyWithinTransaction()` is
the narrow writer. It requires an active caller transaction and never begins,
commits, or rolls back one. Under the transaction it:

1. validates the exact expected projection and event with the P3B-1 planner;
2. locks the matching Store Lite order;
3. checks the globally unique opaque event evidence;
4. rechecks order identity, snapshot, hosted method, amount, currency, and all
   three current states;
5. performs at most one compare-and-swap state update plus one bounded history
   append; and
6. rereads both rows and verifies the transaction remains active.

The registered Store Lite service is the writer's caller. It opens only the
configured client-local RED-CMS database, begins the transaction, commits only
`applied`, exact `replayed`, or valid `unchanged`, and rolls back every refusal
or failure. The adapter never owns the commercial-order transaction.

## Replay And Ordering

An unseen transition stores one opaque event hash and deterministic transition
plan hash. Exact replay returns the stored transition without another update
or history row. Reuse against another order or different transition is a
conflict. New evidence with a stale expected order is refused.

`failed`, `cancelled`, and `expired` remain valid no-change decisions while the
order is pending and append no Store Lite payment history. The future adapter
owns provider-event receipt evidence for those outcomes. Paid, confirmed full
refund, and reversal remain distinct. Reversal sets fulfillment to `blocked`;
it does not synthesize cancellation or refund.

Order-creation idempotency now reads the immutable `order.created` fact rather
than assuming it is the only history row. Therefore retrying the original
checkout key after a legitimate payment transition still returns the original
order snapshot without creating another order.

## Remaining P3B Gate

P3B-4 remains separate. It must stage the exact package through disabled,
enabled, transition, disable, and re-enable states in a fresh project and prove
duplicate, out-of-order, forced rollback, retained rows, stopped execution,
restored ownership, and exact schema/grant/package/process cleanup.

P3B-3 does not add an HTTP route, reuse browser public-mutation ingress, resolve
a secret, contact Stripe/PayPal/Nequi, install an adapter, or modify the clean
RED-CMS starter or any client database.
