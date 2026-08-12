# Store Lite Guest Order Snapshot Contract

Status: implemented as a pure, unregistered Store Lite 0.1.25 package
boundary. It creates no table, order, checkout session, payment, route,
browser control, or runtime service behavior.

## Purpose

The contract fixes the commercial shape that later package-owned persistence
must write atomically. It accepts only a complete server-derived cart, a closed
guest checkout intent, and current installation configuration. A successful
result contains one deterministic immutable order snapshot, its SHA-256, the
exact source cart-state SHA-256, and separate initial order, payment, and
fulfillment states.

The browser never supplies SKU, title, selected option labels, price, currency,
line total, delivery fee, payment state, or order state. Those facts come from
current server cart data and server-local package configuration.

## Fulfillment

Each client installation may enable `pickup`, `delivery`, or both. One order
selects exactly one enabled method.

- Pickup stores no delivery address and adds no fulfillment fee.
- Delivery requires a bounded recipient phone and a closed address containing
  line 1, city, region, ISO two-letter country, and optional line 2, postal
  code, and instructions.
- Delivery adds only the installation's server-derived integer minor-unit flat
  fee. Zones, carrier quotes, weight rules, and per-variant shipping remain out
  of scope.

## Payment intent

The order snapshot records a selected payment intent, never a browser-claimed
payment result:

| Method | Kind | Initial payment state |
| --- | --- | --- |
| `pay_on_receipt` | deferred | `due_on_receipt` |
| `stripe_checkout` | hosted | `pending` |
| `paypal` | hosted | `pending` |
| `zelle_manual` | manual transfer | `pending` |
| `nequi` | hosted | `pending` |

Apple Pay and Google Pay are funding methods selected inside an eligible
Stripe checkout. Venmo is selected inside an eligible PayPal checkout. They
are intentionally not separate Store Lite adapters or order methods.

Allowing a hosted method in configuration does not prove that an adapter is
installed, configured, or healthy. A later checkout preflight must require the
current adapter lifecycle, secret availability, provider capability, and
server-to-server event contract before presenting that option to a buyer.
Zelle remains manual unless a client-specific verified bank integration is
separately approved.

## Immutable snapshot

The version 1 snapshot contains only:

```text
currency
customer name, email, optional phone
fulfillment method, fee, optional delivery address
payment method and provider-neutral kind
product ID, optional variant ID, SKU, title, selected option labels,
quantity, unit amount, currency, and line total for each line
quantity total, subtotal, and order total
```

The snapshot contains no database record identifiers, raw anonymous-subject
token, cookie, CSRF value, idempotency key, provider credential, provider
reference, card data, callback claim, or administrator state. Later product,
variant, price, address, or configuration edits cannot rewrite it.

Order, payment, and fulfillment states are distinct. Every successful build
begins with order `pending` and fulfillment `unfulfilled`; the payment state is
`due_on_receipt` only for pay on receipt and otherwise `pending`. No successful
build produces `paid`.

## Deliberate exclusions

This gate does not:

- load or lock a cart in the database;
- persist an order, line, address, status, activity, or idempotency fact;
- clear the source cart or reserve/decrement stock;
- resolve a request, cookie, anonymous subject, or browser evidence;
- render a checkout form or expose a public mutation;
- invoke Stripe, PayPal, Zelle, Nequi, Apple Pay, Google Pay, or Venmo;
- resolve credentials, create hosted checkout, or accept a payment event; or
- authorize a Store Lite deployment or richer-package enablement.

Those require separate migration, persistence, public-mutation, adapter,
server-event, browser, rollback, and client-isolation gates.

## Verification

`tests/guest-order-snapshot-self-test.php` is dependency-free. It proves
pickup and delivery, a flat server-derived delivery fee, simple and exact
Size/Color line snapshots, deterministic hashes, separate initial states,
the approved payment-method taxonomy, delegated wallet refusal, invalid or
disabled fulfillment refusal, address and phone rules, integer money, forged
total refusal, closed input shapes, and uniform no-partial-data failure.
