# Store Lite Subscription Offer Contract

Status: provider-neutral contract, client-local offer persistence,
administrator editing, and a dedicated public Subscription component in Store
Lite 0.1.48. The core-rendered button records only a client-local subscription
intent; provider Checkout and subscription activation are not connected.

Store Lite subscription buttons are a separate purchase path from ordinary
cart lines and one-time guest orders. The first contract supports explicit
monthly and yearly offers with:

- one Store Lite product and optional variant identity;
- installation-currency minor-unit pricing;
- draft, published, or archived state;
- available or unavailable status; and
- a bounded administrator-selected button label.

The Subscription component has its own offer placement and does not replace or
modify the Product component's one-time Add-to-cart form. Only a published,
available offer can render. The button declares one bounded Offer ID; RED-CMS
owns the anonymous subject, CSRF token, rate limit, idempotency key,
transaction, audit record, and response.

The intent table stores only the anonymous core subject relationship, offer
relationship, offer-state SHA-256, and `requested` status. It accepts no
provider price ID, URL, secret, customer data, raw webhook data, or browser
commercial fact. Exact replay is unchanged instead of creating a duplicate.

The package-owned offer table relates one offer to an existing product and an
optional variant through enforced foreign keys. Writes require a caller-owned
transaction and exact current-state evidence; duplicate, stale, foreign, or
malformed writes fail closed. The table remains empty on installation.

## Required next gates

1. Add a separately distributed payment adapter that translates the bounded
   offer into provider subscription Checkout without exposing secrets.
2. Coordinate the accepted intent with provider Checkout and a browser redirect
   without allowing the browser to supply price or customer authority.
3. Require signed webhook agreement before activating, renewing, cancelling,
   or revoking any subscription entitlement.
4. Complete browser, accessibility, and client-isolation acceptance with the
   selected adapter before any demo or production deployment.
5. Complete rollback and recovery rehearsal for provider failure, abandoned
   Checkout, duplicate events, and disable/re-enable behavior.
   acceptance before any demo or production deployment.

Until all five gates pass, the button can record only a provider-neutral local
intent. It cannot create a subscription, payment, customer, entitlement,
browser redirect, or provider request.
