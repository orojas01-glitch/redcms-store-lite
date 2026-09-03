# Commerce review-cart foundation

Store Lite `0.1.51` adds provider-neutral contracts and append-only tables for
client-review carts without changing the existing storefront cart/order path.

## Authoritative cart contract

`RED_CMS_Store_Lite_Commerce_Review_Cart` accepts only a trusted server-side
draft. It rejects unknown keys, invalid quantities, duplicate items, malformed
customer data, inconsistent expiry, zero-value lines, and unbounded totals. The
browser is never a source for Price IDs, prices, tax, descriptions, subtotals,
eligibility, or state.

The normalized snapshot maintains separate setup and recurring totals:

- setup subtotal;
- recurring monthly subtotal;
- amount due today, equal to setup plus the first monthly charge;
- future monthly renewal amount;
- tax status `not_configured`.

The intended installation policy is seven days for a sales-assisted cart and
24 hours for a configurator handoff. Those values are asserted by the package
tests and must be configured explicitly by the client service.

## Opaque link and lifecycle contracts

`RED_CMS_Store_Lite_Commerce_Review_Cart_Share` accepts a 32-byte random token
encoded as 43 base64url characters and emits only its SHA-256 for persistence.
The raw token is transient and must appear only in the final opaque cart URL.

`RED_CMS_Store_Lite_Commerce_Review_Cart_Transition` constrains the states
`draft`, `shared`, `checkout_pending`, `paid`, `expired`, `canceled`, and
`payment_failed`. It treats matching event evidence as an idempotent replay and
keeps commercial payment state separate from technical onboarding. A paid cart
starts onboarding in `pending`; it never claims that implementation or
provisioning completed automatically.

## Persistence boundary

The `2026-09-02-create-commerce-review-carts.sql` migration creates four new
tables for carts, lines, share-token hashes, and event evidence. It does not
alter the legacy cart/order tables. Installation must use only the isolated
commerce database and database user.

## Deliberately not wired

This release adds no administrator workflow, public review route, handoff
endpoint, database service, payment adapter call, secret, provider request, or
deployment. Those pieces are client-specific work for the isolated commerce
installation. The package foundation can therefore be validated and released
without modifying the existing demo store or any production site.
