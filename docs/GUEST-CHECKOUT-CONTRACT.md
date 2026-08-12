# Store Lite guest checkout contract

Status: linked to the core-owned public-mutation runner in Store Lite 0.1.28.

## Purpose

This gate fixes the browser-scalar input and data-only presentation shapes for
guest checkout. A successful decode produces the exact `checkout` input already
accepted by the 0.1.25 immutable guest-order snapshot. It does not load a cart,
persist an order, reserve stock, call a provider, or expose a public endpoint.

## Closed browser input

`RED_CMS_Store_Lite_Guest_Checkout_Command::decode()` accepts exactly twelve
ordered URL-encoded string fields:

1. `customer-name`
2. `customer-email`
3. `customer-phone`
4. `fulfillment-method`
5. `delivery-line1`
6. `delivery-line2`
7. `delivery-city`
8. `delivery-region`
9. `delivery-postal-code`
10. `delivery-country-code`
11. `delivery-instructions`
12. `payment-method`

The complete encoded body is capped at 4,096 bytes. Unknown, missing,
reordered, nested, non-string, non-canonical, control-character, malformed, or
over-bound input fails uniformly as `invalid_intent` with no partial checkout
or PII result.

The browser cannot submit cart identity, product, variant, SKU, option label,
currency, price, subtotal, fee, total, order state, payment state, provider
reference, provider result, inventory claim, CSRF evidence, idempotency value,
subject identity, or database identity through this contract.

## Fulfillment rules

The current installation configuration remains the source of allowed pickup
and delivery methods and the integer delivery fee.

- Pickup permits an optional contact phone but requires every delivery-address
  field to be the empty string. The decoded address is `null`.
- Delivery requires a valid phone, line 1, city, region, and uppercase
  ISO 3166-1 alpha-2 country code. Line 2, postal code, and instructions remain
  optional and decode from an empty string to `null`.
- Names, addresses, and instructions must already be trimmed valid UTF-8 text
  within the 0.1.25 snapshot bounds. Email and phone formats remain bounded.
- The decoder never accepts a browser fee. The later snapshot derives pickup
  fee zero or the current configured delivery fee.

## Payment readiness

Configuration alone is not sufficient to expose a payment method. The caller
must also supply one exact current server-side boolean readiness decision for
each reserved method:

- `pay_on_receipt`
- `stripe_checkout`
- `paypal`
- `zelle_manual`
- `nequi`

Only the intersection of configured and currently ready methods may appear or
decode. If no configured method is ready, checkout presentation is absent.

This pure gate does not determine readiness. A later integration must derive a
true value from the relevant current policy and adapter state. Hosted-provider
readiness must include enabled installation state, required credentials,
supported currency/country capability, return/cancel URL policy, and a verified
server event contract. Manual Zelle readiness must include the approved
installation-specific instructions and reconciliation policy. A caller must
not pass `true` merely because a method name exists in configuration.

The readiness map is not persisted in the immutable order. The selected method
and provider-neutral kind are snapshotted; later provider execution and events
remain separate gates.

## Presentation and runtime linkage

`RED_CMS_Store_Lite_Public_Guest_Checkout_Presenter::present()` returns one
bounded model with:

- form identity, title, submit label, encoding, and body limit;
- name, email, and phone fields;
- enabled pickup/delivery choices with server-derived currency and fee facts;
- delivery-only visibility and required conditions for address fields; and
- only currently ready payment choices.

The richer descriptive model still returns no route, action, HTML, script,
subject, CSRF token, idempotency key, credential, provider reference, or
database value. A separate `mutationForm()` adapter returns only the exact
core presentation shape: the declared route and mutation, twelve bounded
controls, pickup and delivery choices, and pay on receipt. A non-empty Cart
component attaches that form; an empty cart does not.

The core alone validates and renders the form, issues subject/CSRF/idempotency
evidence, rate limits and dispatches the request, resolves the five declared
non-secret installation settings, opens the transaction, records replay and
audit evidence, and owns the response. Store Lite registers one typed bridge.
It reconstructs the decoder's required field order, admits only configured
pickup/delivery plus ready pay on receipt, locks the current cart, builds the
server-authoritative snapshot, persists the order, and consumes the cart in
the same caller-owned transaction.

The Gate 0 component presentation deliberately offers both pickup and delivery
because component callbacks do not receive installation settings. Submission
still fails closed unless the exact client-scoped settings are configured and
authorize the chosen method. Hiding unavailable methods in presentation is a
later generic component-settings capability; the package does not query core
settings tables directly.

## Deliberate exclusions

This gate still does not add:

- package-owned request parsing, anonymous-subject resolution, CSRF,
  idempotency keys, rate limiting, transactions, response emission, redirects,
  or browser evidence;
- inventory reservation or decrement;
- provider preflight implementation, credentials, hosted sessions, payment
  redirects, webhooks, reconciliation, paid transitions, or refunds;
- order administration, fulfillment transitions, or customer notifications.

## Verification

`tests/guest-checkout-contract-self-test.php` is dependency-free. It proves the
exact field and byte bounds, payment-readiness intersection, data-only model,
exact core form adapter, conditional delivery rules, pickup and delivery
decoding, direct compatibility with the immutable snapshot, unready-provider
refusal, closed browser input, bounded PII, uniform no-partial-data failures,
and absence of package-owned request, output, or network paths. The disposable
MySQL persistence test proves pickup and delivery pay-on-receipt creation,
configured fee use, atomic cart consumption, and hosted-method refusal.
