# Store Lite Payment-Event Transition Contract

Status: P3B-1 is implemented as a pure decision contract in Store Lite
0.1.32. It does not migrate a database, register `commerce.orders`, invoke an
adapter, expose a route, or change an order.

## Purpose

`RED_CMS_Store_Lite_Payment_Event_Transition::plan()` defines the exact
provider-neutral state decision that the later transactional Store Lite
payment-event service must enforce. Store Lite owns the commercial order and
its state transition; a separately installed adapter may supply only one
already-verified normalized event.

This slice deliberately precedes storage and runtime work so the order-state
policy can be reviewed and tested without a package registrar, database,
credential, webhook, provider SDK, or network connection.

## Current Order Projection

The caller supplies exactly these current server-derived facts:

- opaque `ord_` order identity and immutable snapshot SHA-256;
- one Store Lite hosted payment method: `stripe_checkout`, `paypal`, or
  `nequi`, with payment kind `hosted`;
- uppercase three-letter currency and integer immutable total; and
- current order, payment, and fulfillment states.

Pay on receipt and manual Zelle cannot enter this adapter-event contract. The
projection contains no customer, address, line-item, database-record, checkout
URL, or provider-attempt data.

## Normalized Event

The event must contain exactly:

- `verification: verified` and `replayStatus: unseen`;
- one P0 outcome: `paid`, `refund_confirmed`, `reversal_reported`, `failed`,
  `cancelled`, or `expired`;
- the exact order identity and immutable snapshot SHA-256;
- the exact Store Lite payment method, integer amount, and currency;
- one lowercase opaque event-evidence SHA-256; and
- one bounded integer occurrence time.

Unknown or extra fields fail closed. Therefore a raw provider body, signature,
event identifier, checkout URL, credential, customer payment method, or
provider error has no input path. P3C must verify, reconcile, and reduce
provider-specific material before constructing this vocabulary.

## Closed State Map

| Outcome | Required current state | Decision |
| --- | --- | --- |
| `paid` | `pending / pending / unfulfilled` | `paid / paid / unfulfilled` |
| `refund_confirmed` | `paid / paid / unfulfilled` | `refunded / refunded / unfulfilled` |
| `reversal_reported` | `paid / paid / unfulfilled` | `paid / reversal_reported / blocked` |
| `failed`, `cancelled`, `expired` | `pending / pending / unfulfilled` | no Store Lite order transition |

The three columns are order status, payment status, and fulfillment status.
Paid never claims fulfillment. Only a confirmed full-amount refund produces
`refunded`. A reversal remains distinct: it neither invents a refund nor
cancels the order, but it explicitly blocks automatic fulfillment.

The current Store Lite boundary supports full-amount payment and refund facts
only. Partial refunds, a second paid transition, refund/reversal before paid,
and failed/cancelled/expired events after paid are refused rather than guessed.

## Evidence And Refusal

The event order, snapshot, payment method, amount, and currency must match the
current immutable order projection exactly. Unverified or replayed events,
unsupported payment methods, malformed hashes, invalid timestamps, unknown
fields, state drift, and every identity or commercial mismatch return no
partial transition and no plan hash.

A valid decision returns only the current and target states, normalized
outcome, optional bounded history event name, opaque event-evidence hash,
occurrence time, state-change flag, reversal fulfillment-block flag, and a
deterministic SHA-256 of those facts. It returns no provider or customer data.

## Deferred P3B Work

P3B-1 is not the operational payment-event service. Later separately reviewed
slices must still add:

1. P3B-2 now supplies the append-only, MySQL-family-compatible expanded order
   and history vocabulary plus replay evidence;
2. a caller-transaction-owned writer must lock and recheck the current order,
   immutable snapshot, exact states, and unseen event evidence;
3. one atomic order update plus bounded history append still needs rollback
   proof;
4. typed `commerce.orders` registration and enabled-package ownership checks
   remain deferred;
5. disposable upgrade, duplicate, out-of-order, disable/re-enable, and exact
   cleanup acceptance.

No later slice may edit an applied migration or copy Store Lite into the clean
RED-CMS starter. Every installation, database, adapter, secret, and rollback
point remains client-local and separately approved.
