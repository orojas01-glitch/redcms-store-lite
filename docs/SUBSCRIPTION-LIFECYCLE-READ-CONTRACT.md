# Subscription Lifecycle Read Contract

Version: Store Lite `0.1.50`.

`commerce.subscriptions` now exposes the read-only operation
`subscription.lifecycle.load`. The only input is one validated `sint_`
reference. Store Lite reloads the matching package-owned lifecycle record and
returns bounded current state:

- intent and offer-state evidence;
- subscription and entitlement status;
- only a hash of the provider Subscription reference;
- the current period end when applicable;
- only a hash of the Checkout Session reference; and
- the latest event-evidence hash.

Missing or malformed references fail closed. No caller-supplied status,
provider reference, period, customer fact, or payment fact can affect the
result. The operation performs no provider request, secret resolution, route
handling, webhook verification, or deployment.

The disposable payment/subscription lifecycle rehearsal now proves this read
against pending state and refuses an unknown reference. It passes 24 assertions
and removes the database, grant, and staged project while preserving the
configured primary database.
