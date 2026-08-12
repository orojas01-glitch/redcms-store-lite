# Store Lite guest checkout contract

Status: implemented as a pure, unregistered Store Lite 0.1.27 package
boundary.

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

## Data-only presentation

`RED_CMS_Store_Lite_Public_Guest_Checkout_Presenter::present()` returns one
bounded model with:

- form identity, title, submit label, encoding, and body limit;
- name, email, and phone fields;
- enabled pickup/delivery choices with server-derived currency and fee facts;
- delivery-only visibility and required conditions for address fields; and
- only currently ready payment choices.

It registers nothing and returns no route, mutation, action, HTML, script,
subject, CSRF token, idempotency key, credential, provider reference, or
database value.

Current RED-CMS public-mutation forms support only hidden, number, and select
controls. They cannot yet validate or render this model's text, email, phone,
textarea, and conditional requirements. The presenter is therefore not added
to `addon.php` and is not returned by the public Cart component. A later core
gate must extend the generic closed form contract before any checkout browser
control can be linked.

## Deliberate exclusions

This gate does not add:

- a manifest route or public-mutation declaration;
- request, anonymous-subject, CSRF, idempotency, rate-limit, dispatch, response,
  redirect, or browser behavior;
- cart loading/locking, order persistence, cart consumption, or inventory
  mutation;
- provider preflight implementation, credentials, hosted sessions, payment
  redirects, webhooks, reconciliation, paid transitions, or refunds;
- order administration, fulfillment transitions, or customer notifications.

## Verification

`tests/guest-checkout-contract-self-test.php` is dependency-free. It proves the
exact field and byte bounds, payment-readiness intersection, data-only model,
conditional delivery rules, pickup and delivery decoding, direct compatibility
with the immutable snapshot, unready-provider refusal, closed browser input,
bounded PII, uniform no-partial-data failures, and absence of database,
request, output, write, or network paths.
