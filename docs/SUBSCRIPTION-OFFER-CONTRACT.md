# Store Lite Subscription Offer Contract

Status: provider-neutral contract plus client-local persistence in Store Lite
0.1.45. Administrator editing and public subscription checkout are not enabled.

Store Lite subscription buttons are a separate purchase path from ordinary
cart lines and one-time guest orders. The first contract supports explicit
monthly and yearly offers with:

- one Store Lite product and optional variant identity;
- installation-currency minor-unit pricing;
- draft, published, or archived state;
- available or unavailable status; and
- a bounded administrator-selected button label.

The pure button preview is deliberately marked
`subscription_adapter_required` and `checkoutEnabled=false`. Store Lite does
not accept provider price IDs, URLs, secrets, customer data, raw webhook data,
or subscription state in this contract.

The package-owned offer table relates one offer to an existing product and an
optional variant through enforced foreign keys. Writes require a caller-owned
transaction and exact current-state evidence; duplicate, stale, foreign, or
malformed writes fail closed. The table remains empty on installation.

## Required next gates

1. Add administrator offer editing with package permissions, activity audit,
   rollback, and upgrade rehearsal.
2. Add a declared public subscription-intent mutation with core-owned subject,
   CSRF, rate-limit, idempotency, and browser evidence.
3. Add a separately distributed payment adapter that translates the bounded
   offer into provider subscription Checkout without exposing secrets.
4. Require signed webhook agreement before activating, renewing, cancelling,
   or revoking any subscription entitlement.
5. Complete disposable lifecycle, browser, accessibility, and client-isolation
   acceptance before any demo or production deployment.

Until all five gates pass, the contract is preview-only and cannot create a
subscription, payment, customer, entitlement, browser redirect, or provider
request.
