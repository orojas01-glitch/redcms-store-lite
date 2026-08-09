# Store Lite Cart-Line Resolver Contract

Status: implemented as a pure, unregistered Store Lite 0.1.12 package
boundary. It creates no cart, table, route, cookie, public control, or runtime
service behavior.

## Purpose

The resolver is the first server-authoritative commerce calculation. A future
public request may declare only:

```text
product, quantity, optional variant
```

The caller must load the current complete product from Store Lite storage and
pass the configured installation currency separately. The resolver repeats the
complete product normalization contract before selecting a sellable record.
It never accepts browser-owned SKU, price, currency, stock, option label, or
line total.

## Fixed input

- `product` is the exact public lowercase product identifier.
- `quantity` is an integer from 1 through 100.
- `variant` is absent for a simple product and required for a variable product.
- Unknown input fields, including price or total, are refused.

The current server product must be normalized, `published`, `available`, and
in the installation currency. A simple product resolves its own SKU, price,
and optional stock. A variable product resolves exactly one current explicit
variant and derives its selected option labels from the normalized parent.

## Closed result

One successful internal result contains only:

```text
productId, variantId, sku, title, optionLabels, quantity,
unitPriceMinor, currency, lineTotalMinor, stockTracked,
stockAvailable, productStateSha256
```

The line total is integer minor-unit multiplication with an explicit overflow
check and maximum of `99,999,999,900`. `productStateSha256` binds the result to
the exact normalized server product used for the calculation. It is internal
state evidence, not a browser-authoritative value.

Every refusal returns a bounded reason and `line: null`. Draft, archived,
unavailable, currency-drifted, malformed, or mismatched products; missing,
stale, mismatched, or unavailable variants; invalid quantities; unknown input;
and insufficient tracked stock never return a partial line.

## Deliberate exclusions

This gate does not:

- open a database or read request/session/cookie state;
- register or invoke `commerce.cart`;
- create or own an anonymous cart identity;
- reserve or decrement inventory;
- persist a cart or cart line;
- expose an add-to-cart control or public route;
- emit a response or cookie; or
- create an order, payment, or checkout behavior.

Those operations require separate package storage, core public-mutation
dispatch, ownership, CSRF, idempotency, transaction, concurrency, and browser
acceptance gates.

## Verification

`tests/cart-line-resolver-self-test.php` is dependency-free apart from loading
the package's pure product normalizer. It proves simple banana and variable
Size/Color T-shirt resolution, integer totals, current-price/state evidence,
bounded untracked stock, option-label derivation, all refusal classes, closed
input/output shapes, and no partial line results.
