# Store Lite Payment-Event History Migration

Status: P3B-2 is implemented as one append-only Store Lite 0.1.33 migration.
It expands storage for the P3B-1 decision vocabulary but does not write an
event, register an operational service, contact a provider, or activate an
adapter.

## Upgrade Boundary

`2026-08-16-expand-payment-event-history.sql` is ordered after the ten applied
0.1.32 migrations. The earlier order-creation migration remains byte-for-byte
unchanged. Existing order headers and `order.created` history facts are
preserved; their three new evidence columns remain `NULL` because those rows
predate a normalized payment event.

The migration uses a portable base projection plus separately version-gated
check replacement:

- Percona/MySQL 5.7 receives only executable column, evidence, and unique-index
  DDL because that family does not enforce the original named checks;
- MySQL 8 replaces the checks through `/*!80016 ... */`; and
- MariaDB replaces them through `/*M!100200 ... */`.

## Closed Storage Vocabulary

The order header admits only these coherent projections:

| Order | Payment | Fulfillment |
| --- | --- | --- |
| `pending` | `pending` or `due_on_receipt` | `unfulfilled` |
| `paid` | `paid` | `unfulfilled` |
| `refunded` | `refunded` | `unfulfilled` |
| `paid` | `reversal_reported` | `blocked` |

Only hosted Stripe Checkout, PayPal, and Nequi order rows may use the three
post-payment states. Pay on receipt remains `due_on_receipt`; manual Zelle
remains `pending`.

History remains append-only and accepts only `order.created`, `payment.paid`,
`payment.refund_confirmed`, and `payment.reversal_reported`. Payment facts use
the package-owned actor `service` with numeric actor `0` and must contain:

- `EventEvidenceSHA256`, an opaque normalized-event digest unique across the
  selected client database;
- `TransitionSHA256`, the deterministic P3B-1 plan digest; and
- `EventOccurredAt`, the provider-neutral bounded occurrence time.

No provider event identifier, raw payload, signature, credential, checkout
URL, payment instrument, customer detail, or error body is stored.

## What This Does Not Prove

Database checks constrain row vocabulary; they do not authorize or sequence a
transition. P3B-3 now locks and rechecks the current order, rejects conflicting
seen evidence, performs one update plus one history append inside the
caller-owned transaction, and registers the typed `commerce.orders` service
only for the enabled owning package. See
[`PAYMENT-EVENT-SERVICE-CONTRACT.md`](PAYMENT-EVENT-SERVICE-CONTRACT.md).

P3B-4 supplies the separate rollback, duplicate, out-of-order,
disable/re-enable, retained-data, and exact-cleanup rehearsal. No migration in
P3B-2 targets a client database or the clean RED-CMS starter.
